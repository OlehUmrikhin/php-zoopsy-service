# PHP SQLite Microservice

Локальна інструкція

- Скопіювати `.env.example` → `.env` та налаштувати
- Встановити залежності:

```bash
composer install
```

- Ініціалізувати бд

```bash
php scripts/init_db.php
```

- Запустити локально:

```bash
php -S localhost:8080 -t public
```

API

- POST /visit
  - body: `{ "page": "/path", "userId": "optional" }`
  - Заголовок `X-API-KEY` якщо заданий у `.env`

- POST /log
  - body: `{ "userId": "...", "event": "click|auth|error|...", "meta": { } }`

- GET /stats?page=/path&period=7d