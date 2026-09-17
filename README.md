# 求人票システム バックアップ

Hreedの求人票サイト一式の控えです。
最終更新：**2026-09-17 15:23**

> **このリポジトリは Private のままにしてください。**
> `public/admin/config.php` に送信用トークンとログインパスワードの
> ハッシュが含まれています。公開された場合は、下の「万一公開してしまったら」を
> 実行してください。

## 中身

| フォルダ | 内容 |
|---|---|
| `public/` | サーバーに置いてあるソース一式（PHP・CSS・JS） |
| `data/jobs/` | 求人データ。1求人＝1ファイル。成約フィーと確認事項を含む |
| `tools/` | JSONの点検スクリプト |
| `skills/` | 求人票の作り方のルール（Claude のスキル） |
| `docs/` | 手順書・運用メモ・メンバー配布物 |
| `push.command.txt` | 送信ツール（トークンは伏せてあります） |

求人データは **35件** です。
push.command が取得したもの。**成約フィーとエージェント確認事項は含まれていません。**

## 復旧のしかた

1. サーバーの `public_html/recruit-file/` に `public/` をまるごとアップロード
2. `data/` を `public/data/` として戻す
   （`data/.htaccess` と `data/jobs/` の両方。**.htaccess を忘れると
   求人データが誰でも直接読めてしまいます**）
3. `public/data/` と `public/data/jobs/` に書き込み権限（755）を付ける
4. `public/check.php` は置かないこと（設置診断用。サーバー情報が見えます）
5. 管理画面にログインして、求人が並んでいれば完了

```
https://ichi-web-home.com/recruit-file/public/admin/
```

## 万一公開してしまったら

トークンとパスワードを作り直してください。古いものは使えなくなります。

```bash
# 新しいトークン
python3 -c "import secrets;print(secrets.token_urlsafe(32))"
```

1. `public/admin/config.php` の `API_TOKENS` を新しい値に差し替える
2. 管理画面の「パスワード変更」で新しいハッシュを発行し、`USERS` に貼る
3. アップロードする
4. メンバーの `push.command` も新しいトークンに差し替えて配り直す

## このバックアップの取り直し方

求人票フォルダの `backup.command` をダブルクリックするだけです。
求人データを最新にするには、先にサーバーの `public/data/jobs/` をFTPで落として、
`server-data` という名前で求人票フォルダに置いてください。
