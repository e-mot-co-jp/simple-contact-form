import numpy as np
import pandas as pd
import sqlalchemy as sa
import sys
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.model_selection import train_test_split
from sklearn.naive_bayes import BernoulliNB

for line in sys.stdin:
    target = str(line)

connection_config ={
    'user':'xs683807_mot',
    'password':'J3JqAR2YggEFwEK',
    'host':'localhost',
    'port':'3306',
    'database':'xs683807_mot2'
}

url = 'mysql+pymysql://{user}:{password}@{host}:{port}/{database}?charset=utf8'.format(**connection_config)
engine = sa.create_engine(url, echo=False)

query = "select * from `spam_list`"
df = pd.read_sql(query,con = engine)

X = df["message"]
Y = df["class"]

X_train, X_test, Y_train, Y_test = train_test_split(X, Y, train_size=0.7, test_size=0.3,random_state=10)

vecount = CountVectorizer(min_df=3)
vecount.fit(X_train)

X_train_vec = vecount.transform(X_train)
X_test_vec = vecount.transform(X_test)

pd.DataFrame(X_train_vec.toarray()[0:5], columns=vecount.get_feature_names_out())

model = MultinomialNB()
model.fit(X_train_vec, Y_train)

data = np.array([target])

df_data = pd.DataFrame(data,columns=['message'])
df_data_vec = vecount.transform(df_data['message'])

pd.DataFrame(df_data_vec.toarray(), columns=vecount.get_feature_names_out())
result = model.predict(df_data_vec)
#print(a)
if result[0] == 'ham':
    print('ham')
else:
    print('spam')