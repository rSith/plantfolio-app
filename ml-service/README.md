# ml-service/ — Flask ML API (stage M)

A small Python service that runs the two image models. **Only PHP talks to it** — the browser never does.
PHP saves the uploaded photo, sends it here with cURL, and stores the JSON reply in `ml_predictions`.
If the service is offline or slow, pages fall back to manual entry.

## Endpoints (built in ML-01, `app.py`)

| Method & path | Input | Returns | Task |
|---|---|---|---|
| `GET /health` | — | `{"status": "ok", "models": {"disease": {"ready": …}, "species": {"ready": …}}}` | ML-01 |
| `POST /predict/species` | multipart `image` (JPG/PNG ≤ 5 MB) | top 3 species (scientific names) with confidence, from **Pl@ntNet** | ML-09 |
| `POST /predict/disease` | multipart `image` (JPG/PNG ≤ 5 MB) | top 3 diseases or pests from **Pl@ntNet** (`DISEASE_PROVIDER=plantnet`), labels `CODE___Name` such as `PHYTIN___Phytophthora infestans - Late blight of potato`; or our own model (`DISEASE_PROVIDER=model`) | ML-10 |

Example response:
```json
{
  "model_version": "disease-mobilenetv2-v1",
  "predictions": [
    {"label": "Tomato___Early_blight", "confidence": 0.92},
    {"label": "Tomato___Septoria_leaf_spot", "confidence": 0.05},
    {"label": "Tomato___healthy", "confidence": 0.02}
  ]
}
```
- Errors return JSON `{"error": "…"}`: `400` no image or unreadable file, `413` over 5 MB, `415` not JPG/PNG,
  `422` Pl@ntNet found no plant, `502` Pl@ntNet failed, `503` disease model or Pl@ntNet key missing. PHP treats every error as "couldn't reach".
- The service listens on `127.0.0.1` only and checks every photo again with Pillow (never trust the caller).
- PHP gives up after **10 seconds** and shows the manual fallback.
- Confidence words are decided in PHP: **High ≥ 80 %**, **Medium 60–79 %**, **Not sure < 60 %**.
- Models are loaded **once** at start-up, not per request.

## Models
| Feature | Plan | Target | Tasks |
|---|---|---|---|
| Disease detection | **Now: Pl@ntNet disease API** (decided 12 Nov 2026, D27; about 2,500 diseases and pests, EPPO codes). **Later, if time:** PlantVillage, 70/15/15 split; baseline CNN from scratch, then fine-tuned MobileNetV2 with augmentation | ≥ 90 % test accuracy; also measured on 20–30 real phone photos | ML-03 – ML-06 |
| Plant recognition | **Pl@ntNet API** (decided 12 Nov 2026, ML-07): Flask forwards the photo with `organs=auto` and returns `scientificNameWithoutAuthor` + score. No species model is trained | Correct species in the top 3 for most of ≥ 30 labelled phone photos | ML-07, ML-08 |

**Scope note:** PlantVillage covers food crops (tomato, potato, pepper, apple, grape, corn …) on plain backgrounds.
The Health Check page lists the supported crops and shows a scope message for other plants instead of guessing.

## Model contract (for the training notebook)
- `models/disease.keras` takes **224 × 224 RGB pixels as 0–255 floats**; its own first layers do the scaling
  (e.g. `Rescaling(1/127.5, offset=-1)` for MobileNetV2), so `app.py` never has to know how it was trained.
- `models/disease_classes.json`: `{"model_version": "disease-mobilenetv2-v1", "classes": ["Apple___Apple_scab", …]}`,
  in the model's output order. The class names are the PlantVillage folder names, the same text as `disease_guides.class_label`.
- `app.py` refuses to start if the number of classes and model outputs differ.

## Folders
- `app.py` — the Flask app · `requirements.txt` — pinned packages · `.env.example` → copy to `.env` (git-ignored) for the Pl@ntNet key
- `notebooks/` — Colab training notebooks (commit them, cleared of large outputs)
- `models/` — trained `.keras`/`.h5` files are **git-ignored** (too large); share via Google Drive — see `models/README.md`

## Setup
```bash
cd ml-service
python -m venv venv
venv\Scripts\activate            # macOS/Linux: source venv/bin/activate
pip install -r requirements.txt
copy .env.example .env         # then paste your Pl@ntNet key into .env
python app.py                    # serves on http://127.0.0.1:5000
```
The URL PHP uses is `ml_service_url` in `config/config.php`.
