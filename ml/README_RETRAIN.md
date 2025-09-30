自動再学習パイプライン (retrain_deploy.sh)

目的
- DB の `spam_list` テーブルを定期的に学習データとして取り込み、モデルを再学習してプラグインに安全にデプロイする。

前提
- サーバに Python3 がインストールされていること。
- `ml/.venv` がある場合、スクリプトはその中の Python を優先して使います。
- `wp` (WP-CLI) がインストールされていると簡単に DB をエクスポートできます。なければ mysql クライアントで接続情報を環境変数で渡してください（`DB_USER`/`DB_PASS`/`DB_NAME`/`DB_HOST`）。

インストール
1) スクリプトに実行権を付与
```bash
chmod +x ml/retrain_deploy.sh
```

2) 必要なら仮想環境を作成して依存を入れる
```bash
cd ml
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
deactivate
```

手動実行
```bash
cd wp-content/plugins/simple-contact-form/ml
./retrain_deploy.sh
# ログは /tmp/scf_retrain.log に追記されます
```

cron で定期実行する例（毎晩 3:30）
```cron
30 3 * * * cd /home/youruser/public_html/wp-content/plugins/simple-contact-form/ml && /usr/bin/env PYTHON_BIN=/home/youruser/public_html/wp-content/plugins/simple-contact-form/ml/.venv/bin/python ./retrain_deploy.sh
```

注意事項
- CSV エクスポートの部分は環境依存です。WP-CLI が利用できない場合は、スクリプトを編集して `mysql` クライアントの接続情報を直書きするか、`.my.cnf` を用いてください。
- 本スクリプトは学習用データをそのまま使って学習するため、データの前処理（重複削除、ラベル品質チェックなど）を追加することを推奨します。
- 重大な変更を行う前に必ずバックアップを取り、最初は手動で数回実行してログを確認してください。
