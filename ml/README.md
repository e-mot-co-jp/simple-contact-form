TF-IDF + LogisticRegression pipeline (simple)

Quick start

1) Prepare a CSV file with two columns: `message` and `label` (label may be 'spam'/'ham' or 1/0). If your export uses `text`/`class`, the script will accept those names too.

2) Install dependencies (virtualenv recommended):

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

3) Train a model:

```bash
python train_tfidf_lr.py --input spam_list.csv --output model.joblib
```

4) Predict a single text:

```bash
echo "営業のご案内です" | python predict_tfidf_lr.py --model model.joblib
# or
python predict_tfidf_lr.py --model model.joblib --text "営業のご案内です"
```

Notes and next steps
- This uses character n-gram TF-IDF (char_wb 2-5) which is a fast, language-agnostic approach that works OK for Japanese without MeCab.
- For higher accuracy, integrate a Japanese tokenizer (fugashi/MeCab) and use word-level TF-IDF, or use embeddings + classifier.
- You can adapt `train_tfidf_lr.py` to read directly from the database if desired.
