#!/usr/bin/env python3
"""
Train TF-IDF + LogisticRegression pipeline for spam detection.

Usage:
  python train_tfidf_lr.py --input data.csv --output model.joblib

CSV must have columns 'message' and 'label' (label: 'spam'/'ham' or 1/0).
"""
import argparse
import os
import sys
import pandas as pd
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.model_selection import train_test_split
from sklearn.metrics import classification_report
import joblib


def load_df(path):
    df = pd.read_csv(path)
    # allow alternative column names
    if 'message' not in df.columns:
        if 'text' in df.columns:
            df = df.rename(columns={'text': 'message'})
    if 'label' not in df.columns:
        if 'class' in df.columns:
            df = df.rename(columns={'class': 'label'})
    if 'message' not in df.columns or 'label' not in df.columns:
        raise ValueError("CSV must contain 'message' and 'label' columns (or 'text'/'class')")
    return df


def normalize_label(series):
    def map_label(v):
        s = str(v).lower()
        if s in ('spam', '1', 'true', 't', 'yes', 'y'):
            return 1
        return 0
    return series.map(map_label)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', '-i', required=True, help='Input CSV path')
    parser.add_argument('--output', '-o', default='model.joblib', help='Output model path')
    parser.add_argument('--test-size', type=float, default=0.2)
    parser.add_argument('--random-state', type=int, default=42)
    args = parser.parse_args()

    df = load_df(args.input)
    X = df['message'].fillna('').astype(str)
    y = normalize_label(df['label'])

    if len(y.unique()) < 2:
        print('Training data must contain at least two classes (spam/ham).', file=sys.stderr)
        sys.exit(2)

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=args.test_size, random_state=args.random_state, stratify=y
    )

    pipe = Pipeline([
        ('tfidf', TfidfVectorizer(analyzer='char_wb', ngram_range=(2,5), max_features=20000)),
        ('clf', LogisticRegression(solver='liblinear', class_weight='balanced', max_iter=1000)),
    ])

    print('Training model...')
    pipe.fit(X_train, y_train)

    print('Evaluating...')
    y_pred = pipe.predict(X_test)
    print(classification_report(y_test, y_pred, digits=4))

    joblib.dump(pipe, args.output)
    print(f'Saved model to {args.output}')


if __name__ == '__main__':
    main()
