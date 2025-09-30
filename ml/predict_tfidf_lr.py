#!/usr/bin/env python3
"""
Load a saved model.joblib and predict input text.

Usage:
  echo "テキスト" | python predict_tfidf_lr.py --model model.joblib
  python predict_tfidf_lr.py --model model.joblib --text "some text"

Outputs a JSON object to stdout: {"label":"spam"|"ham", "probability":0.87}
"""
import argparse
import sys
import json
import joblib


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--model', '-m', default='model.joblib', help='path to model.joblib')
    parser.add_argument('--text', '-t', help='text to classify (if not provided, read stdin)')
    args = parser.parse_args()

    if args.text:
        text = args.text
    else:
        text = sys.stdin.read().strip()

    if not text:
        print(json.dumps({'error': 'no input'}), ensure_ascii=False)
        sys.exit(2)

    model = joblib.load(args.model)
    proba = model.predict_proba([text])[0]
    pred = int(model.predict([text])[0])
    label = 'spam' if pred == 1 else 'ham'
    result = {'label': label, 'probability': float(proba[pred])}
    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    main()
