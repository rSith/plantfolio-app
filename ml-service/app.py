"""PlantFolio ML service (ML-01, ML-09, ML-10): a small Flask API that only PHP talks to.

POST /predict/species  -> Pl@ntNet species identification (decided 12 Nov 2026, D26)
POST /predict/disease  -> Pl@ntNet disease and pest identification (decided 12 Nov 2026, D27), or our own
                          Keras model in models/ when DISEASE_PROVIDER=model (if we train one later)
GET  /health           -> which parts are ready

Both predict routes take a multipart field "image" (JPG or PNG, at most 5 MB) and answer in the same shape,
so includes/ml-client.php does not care where an answer came from:
    {"model_version": "...", "predictions": [{"label": "...", "confidence": 0.92}, ...]}   (top 3, best first)
Species labels are scientific names ("Monstera deliciosa"). Disease labels are "CODE___Name": the EPPO code
Pl@ntNet uses and its readable name ("COLLDE___Colletotrichum dematium - Anthracnose of capsicum"), the same
"___" pattern as PlantVillage's "Tomato___Early_blight".
Errors are JSON too: {"error": "..."} with 400 bad image, 413 too big, 415 wrong type, 422 no plant found,
502 Pl@ntNet failed, 503 not configured.
"""

import io
import json
import logging
import os
from pathlib import Path

import numpy as np
import requests
from flask import Flask, jsonify, request
from PIL import Image, UnidentifiedImageError

BASE_DIR = Path(__file__).resolve().parent
MODELS_DIR = BASE_DIR / "models"


def load_env_file(path: Path) -> None:
    """Read KEY=value lines from ml-service/.env (git-ignored) into the environment, so the
    Pl@ntNet key never sits in the code. A real environment variable wins over the file."""
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


load_env_file(BASE_DIR / ".env")

PLANTNET_BASE = "https://my-api.plantnet.org/v2"
PLANTNET_KEY = os.environ.get("PLANTNET_API_KEY", "")
# PHP gives up after 10 s, so Pl@ntNet gets less than that
PLANTNET_TIMEOUT = float(os.environ.get("PLANTNET_TIMEOUT", "8"))
# "plantnet" (now) or "model" (our own Keras model in models/, if we train one later)
DISEASE_PROVIDER = os.environ.get("DISEASE_PROVIDER", "plantnet").strip().lower()
MAX_UPLOAD_BYTES = 5 * 1024 * 1024
TOP_K = 3
LABEL_MAX = 150   # ml_predictions.predicted_label is VARCHAR(150)

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = MAX_UPLOAD_BYTES + 64 * 1024   # the photo plus the multipart wrapping
app.json.sort_keys = False
log = logging.getLogger("plantfolio-ml")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")


# ── Own disease model (only when DISEASE_PROVIDER=model): loaded once at start-up, not per request ─────

def load_disease_model():
    """Returns (model, class_names, version) or (None, [], None) when the files are not there.
    The service still starts without them: /predict/disease answers 503 and PHP shows its fallback."""
    model_file = MODELS_DIR / "disease.keras"
    classes_file = MODELS_DIR / "disease_classes.json"
    if not model_file.exists() or not classes_file.exists():
        log.warning("Disease model not found in %s: /predict/disease will answer 503", MODELS_DIR)
        return None, [], None

    import tensorflow as tf   # imported only here: the Pl@ntNet setup never needs TensorFlow

    model = tf.keras.models.load_model(model_file)
    info = json.loads(classes_file.read_text(encoding="utf-8"))
    class_names = info["classes"]
    if model.output_shape[-1] != len(class_names):
        raise RuntimeError(f"disease.keras has {model.output_shape[-1]} outputs but disease_classes.json lists {len(class_names)}")
    log.info("Disease model loaded: %d classes, input %s", len(class_names), model.input_shape)
    return model, class_names, info.get("model_version", "disease-mobilenetv2-v1")


if DISEASE_PROVIDER == "model":
    disease_model, disease_classes, disease_version = load_disease_model()
else:
    disease_model, disease_classes, disease_version = None, [], None


# ── Helpers ──────────────────────────────────────────────────────────────────────────────────────────

def error(message: str, status: int):
    return jsonify({"error": message}), status


def read_image():
    """The uploaded photo as (raw bytes, PIL image, mime) or an error response.
    PHP already checked the file, but this service checks it again: never trust the caller."""
    file = request.files.get("image")
    if file is None or file.filename == "":
        return None, error("Send the photo in a multipart field named 'image'.", 400)
    data = file.read()
    if len(data) > MAX_UPLOAD_BYTES:
        return None, error("The photo is larger than 5 MB.", 413)
    try:
        image = Image.open(io.BytesIO(data))
        image_format = image.format
        image.load()   # decodes the pixels: a broken file fails here, not later
    except (UnidentifiedImageError, OSError):
        return None, error("This file is not a readable image.", 400)
    if image_format not in ("JPEG", "PNG"):
        return None, error("Only JPG and PNG photos are accepted.", 415)
    mime = "image/jpeg" if image_format == "JPEG" else "image/png"
    return (data, image.convert("RGB"), mime), None


def top_predictions(pairs):
    """[(label, confidence), ...] -> the best TOP_K as the JSON list PHP expects, confidence 0–1 with 4 decimals."""
    pairs = sorted(pairs, key=lambda pair: pair[1], reverse=True)[:TOP_K]
    return [{"label": label[:LABEL_MAX], "confidence": round(min(max(float(conf), 0.0), 1.0), 4)} for label, conf in pairs]


def ask_plantnet(path: str, data: bytes, mime: str, extra_form=None):
    """POST one photo to a Pl@ntNet route. Returns (reply JSON, None) or (None, error response)."""
    try:
        reply = requests.post(
            f"{PLANTNET_BASE}/{path}",
            params={"api-key": PLANTNET_KEY, "nb-results": TOP_K, "lang": "en"},
            files=[("images", ("photo." + mime.split("/")[1], data, mime))],   # Pl@ntNet's field is "images"
            data=extra_form or {},
            timeout=PLANTNET_TIMEOUT,
        )
    except requests.RequestException as exc:
        # Only the kind of problem is logged: the request URL carries the API key
        log.warning("Pl@ntNet did not answer: %s", type(exc).__name__)
        return None, error("Pl@ntNet did not answer.", 502)

    if reply.status_code == 404:
        # Pl@ntNet's way of saying it found no plant in the photo
        return None, error("No plant was recognised in this photo.", 422)
    if reply.status_code != 200:
        log.warning("Pl@ntNet answered HTTP %s on %s", reply.status_code, path)
        return None, error("Pl@ntNet is not available.", 502)
    try:
        answer = reply.json()
        if not isinstance(answer.get("results"), list):
            raise ValueError
    except ValueError:
        log.warning("Pl@ntNet sent an answer in an unexpected shape on %s", path)
        return None, error("Pl@ntNet sent an unusable answer.", 502)
    log.info("Pl@ntNet %s: %s requests left today", path, answer.get("remainingIdentificationRequests", "?"))
    return answer, None


def plantnet_version(answer, kind: str) -> str:
    """'plantnet-species 2026-03-20 (7.5)': which Pl@ntNet engine answered, kept in ml_predictions.model_version."""
    return f"plantnet-{kind} {answer.get('version', '')}".strip()[:50]


# ── Routes ───────────────────────────────────────────────────────────────────────────────────────────

@app.get("/health")
def health():
    if DISEASE_PROVIDER == "model":
        disease = {"ready": disease_model is not None, "provider": "own model", "classes": len(disease_classes), "version": disease_version}
    else:
        disease = {"ready": bool(PLANTNET_KEY), "provider": "Pl@ntNet"}
    return jsonify({
        "status": "ok",
        "models": {"disease": disease, "species": {"ready": bool(PLANTNET_KEY), "provider": "Pl@ntNet"}},
    })


@app.post("/predict/species")
def predict_species():
    if not PLANTNET_KEY:
        return error("No Pl@ntNet API key: add PLANTNET_API_KEY to ml-service/.env.", 503)
    upload, problem = read_image()
    if problem:
        return problem
    data, _, mime = upload

    answer, problem = ask_plantnet("identify/all", data, mime, {"organs": "auto"})
    if problem:
        return problem
    try:
        pairs = [(r["species"]["scientificNameWithoutAuthor"], r["score"]) for r in answer["results"]]
    except (KeyError, TypeError):
        return error("Pl@ntNet sent an unusable answer.", 502)
    if not pairs:
        return error("No plant was recognised in this photo.", 422)
    return jsonify({"model_version": plantnet_version(answer, "species"), "predictions": top_predictions(pairs)})


@app.post("/predict/disease")
def predict_disease():
    if DISEASE_PROVIDER == "model":
        if disease_model is None:
            return error("The disease model is not loaded.", 503)
        upload, problem = read_image()
        if problem:
            return problem
        _, image, _ = upload
        # The model's first layers do the scaling (see the model contract in README.md), so it gets 0–255 pixels
        height, width = disease_model.input_shape[1:3]
        pixels = np.asarray(image.resize((width, height), Image.BILINEAR), dtype=np.float32)
        probabilities = disease_model.predict(pixels[np.newaxis, ...], verbose=0)[0]
        return jsonify({"model_version": disease_version, "predictions": top_predictions(zip(disease_classes, probabilities))})

    if not PLANTNET_KEY:
        return error("No Pl@ntNet API key: add PLANTNET_API_KEY to ml-service/.env.", 503)
    upload, problem = read_image()
    if problem:
        return problem
    data, _, mime = upload

    answer, problem = ask_plantnet("diseases/identify", data, mime)
    if problem:
        return problem
    try:
        # "COLLDE___Colletotrichum dematium - Anthracnose of capsicum": the code finds the saved guide, the name is shown
        pairs = [(f"{r['name']}___{r.get('description') or r['name']}", r["score"]) for r in answer["results"]]
    except (KeyError, TypeError):
        return error("Pl@ntNet sent an unusable answer.", 502)
    if not pairs:
        return error("No plant was recognised in this photo.", 422)
    return jsonify({"model_version": plantnet_version(answer, "diseases"), "predictions": top_predictions(pairs)})


@app.errorhandler(413)
def too_large(_):
    return error("The photo is larger than 5 MB.", 413)


@app.errorhandler(404)
def not_found(_):
    return error("Not found.", 404)


@app.errorhandler(405)
def wrong_method(_):
    return error("Method not allowed.", 405)


if __name__ == "__main__":
    # 127.0.0.1 only: the service is for PHP on this machine, never for the open network
    app.run(host="127.0.0.1", port=int(os.environ.get("ML_PORT", "5000")), debug=False)
