モデル配置手順 (model.joblib)

目的
- ローカルで学習した `model.joblib` を WordPress プラグインの所定位置に置き、`sales_block.py` がそれを使って高速推論するようにする。

手順
1) ローカルで `train_tfidf_lr.py` を実行して `model.joblib` を作成する（既に作成済みならスキップ）

2) サーバへ `model.joblib` を転送する（scp/sftp/FTP など）

例: scp を使う (ローカル端末で実行)
```bash
scp model.joblib user@server:/home/youruser/tmp/model.joblib
```

3) サーバ上でプラグインディレクトリに移動してスクリプトを使う
```bash
cd /home/youruser/public_html/wp-content/plugins/simple-contact-form
./install_model.sh /home/youruser/tmp/model.joblib
```

4) パーミッションの確認
- ファイルが `model.joblib` としてプラグインルートに存在すること
- PHP/Apache/nginx の実行ユーザが読み取れるパーミッション（一般的には 644）

5) テスト
- 簡易テスト（サーバ上）:
```bash
# 直接 sales_block.py を呼んでみる
echo "営業のご案内です" | python3 sales_block.py
# 期待: 'spam' または 'ham' が出力される
```

- WordPress の管理画面からも既存のテストUIで確認してください。

注意
- `model.joblib` を作った環境とサーバの Python 環境（パッケージバージョン）が大きく異なるとロード時にエラーが出ることがあります。可能ならサーバ側でも同じ `scikit-learn`/`joblib` のバージョンをインストールしてください。
- 共有ホスティングで `pip install` が使えない場合は、ローカルで `predict_tfidf_lr.py` も含めて小さな実行バイナリを用意するなどの代替が必要です。
