=== Simple Contact Form ===
Contributors: yu ishiga
Tags: contact, form, file upload, yubinbango, address auto-fill
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

シンプルなお問い合わせフォーム（ファイル添付・郵便番号→住所自動入力(yubinbango)対応）。

== Description ==
Simple Contact Formは、WordPressサイトに簡単に設置できる日本語対応のお問い合わせフォームです。
- ファイル添付（複数可・40MBまで・jpg/jpeg/gif/pdf/heic/png）
- 郵便番号→住所自動入力（yubinbango.js）
- Cloudflare Turnstile対応
- 入力内容確認画面・送信完了画面
- プライバシーポリシー同意チェック

== Installation ==
1. プラグインファイルを `/wp-content/plugins/simple-contact-form` ディレクトリにアップロードします。
2. WordPress管理画面から有効化します。
3. ショートコード `[simple_contact_form]` を投稿や固定ページに追加してください。

== Frequently Asked Questions ==
= ファイル添付の制限は？ =
40MBまで、jpg/jpeg/gif/pdf/heic/png形式に対応しています。

= 郵便番号自動入力はどのAPIを使っていますか？ =
yubinbango.js（https://yubinbango.github.io/yubinbango/）を利用しています。

== Screenshots ==
1. フォーム画面
2. 入力内容確認画面
3. 送信完了画面

== Changelog ==
= 1.1.0 =
* spam_listテーブル自動作成・自動登録機能を追加
* 問合せごとのspam/ham判定履歴を管理画面から編集可能に

= 1.0.0 =
* 初回リリース

== Upgrade Notice ==
= 1.1.0 =
* spam_list管理機能追加。アップグレード推奨。

= 1.0.0 =
* 初回リリース
