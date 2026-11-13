# ml-service/models/

Trained model files go here but are **not committed** (see `.gitignore`).

| File | Model | Download |
|---|---|---|
| `disease.keras` | Disease detection (MobileNetV2, PlantVillage) | _(add Google Drive link — ML-05)_ |
| — | Plant recognition uses the Pl@ntNet API, no model file (ML-07, 12 Nov 2026) | — |
| `*_classes.json` | Class-name lists saved with each model | committed alongside the notebooks |

Each model gets a short model card in `docs/` (data, classes, metrics, limitations) — task DEP-08.
