# WordPress di test

WordPress vuoto in Docker per sviluppare e provare il plugin fuori dai progetti clienti. La cartella del plugin è montata nel container: ogni modifica si vede subito, senza copiare file.

```bash
dev/setup.sh                                   # avvia (e al primo giro installa) il sito
docker compose -f dev/docker-compose.yml down     # ferma
docker compose -f dev/docker-compose.yml down -v  # ferma e azzera tutto (db, uploads, note)
docker compose -f dev/docker-compose.yml run --rm cli wp <comando>   # WP-CLI
```

| Cosa | Dove |
|---|---|
| Sito (vista cliente, non loggato) | http://localhost:8095 |
| Bacheca | http://localhost:8095/wp-admin — utente `admin`, password `admin` (solo test locale) |
| Email inviate dal sito (Mailpit) | http://localhost:8035 |
| Log PHP | `docker compose -f dev/docker-compose.yml exec wordpress tail -f wp-content/debug.log` |

Il setup attiva il plugin e le note (`dcn_enabled`) e crea tre pagine di prova. Le email passano da `dev/mu-plugins/dcn-dev-mail.php` verso Mailpit, quindi le impostazioni SMTP del plugin vanno lasciate vuote.

Altre versioni: `WP_TAG=6.8-php8.1-apache dev/setup.sh` (dopo un `down -v`). Porte diverse: `WP_PORT` e `MAIL_PORT`.

La cartella `dev/` è esclusa dagli zip di rilascio (`.gitattributes`).
