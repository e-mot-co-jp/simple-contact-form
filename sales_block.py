import os
import sys
import joblib
import numpy as np
import pandas as pd
import sqlalchemy as sa
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.model_selection import train_test_split


def predict_with_saved_model(model_path, text):
    try:
        clf = joblib.load(model_path)
        # If it's a pipeline, it should accept raw text list
        pred = clf.predict([text])[0]
        # Normalize prediction to 'spam'/'ham'
        if str(pred).lower() in ('1', 'spam', 'true', 't'):
            return 'spam'
        return 'ham'
    except Exception as e:
        # fallback to DB training
        print(f'WARN: failed to load/predict with saved model: {e}', file=sys.stderr)
        return None


def predict_with_db_training(text):
    try:
        # Read DB and train a simple CountVectorizer + MultinomialNB model (existing behavior)
        connection_config = {
            'user': 'xs683807_mot',
            'password': 'J3JqAR2YggEFwEK',
            'host': 'localhost',
            'port': '3306',
            'database': 'xs683807_mot2'
        }
        url = 'mysql+pymysql://{user}:{password}@{host}:{port}/{database}?charset=utf8'.format(**connection_config)
        engine = sa.create_engine(url, echo=False)

        query = "select * from `spam_list`"
        df = pd.read_sql(query, con=engine)
        if df.shape[0] == 0:
            print('WARN: spam_list table empty', file=sys.stderr)
            return 'ham'

        X = df['message'].astype(str)
        Y = df['class']

        # Train/test split is not critical here; we train on available data to mimic previous behavior
        X_train, X_test, Y_train, Y_test = train_test_split(X, Y, train_size=0.7, test_size=0.3, random_state=10)

        vecount = CountVectorizer(min_df=3)
        vecount.fit(X_train)

        X_train_vec = vecount.transform(X_train)

        model = MultinomialNB()
        model.fit(X_train_vec, Y_train)

        df_data = pd.DataFrame([text], columns=['message'])
        df_data_vec = vecount.transform(df_data['message'])
        result = model.predict(df_data_vec)
        if str(result[0]).lower() == 'ham' or str(result[0]) == '0':
            return 'ham'
        return 'spam'
    except Exception as e:
        print(f'ERROR: db training/predict failed: {e}', file=sys.stderr)
        # Safe default
        return 'ham'


def main():
    # Read entire stdin as input text
    input_text = sys.stdin.read().strip()
    if not input_text:
        # nothing to classify
        print('ham')
        return

    # Prefer saved model if available
    model_path = os.path.join(os.path.dirname(__file__), 'model.joblib')
    if os.path.exists(model_path):
        pred = predict_with_saved_model(model_path, input_text)
        if pred is not None:
            print(pred)
            return

    # Fallback: train from DB and predict
    pred = predict_with_db_training(input_text)
    print(pred)


if __name__ == '__main__':
    main()