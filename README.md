# UA Pay Box 1.3.9

Платіжний центр для WordPress-плагінів, Android та Chrome. **Єдиний спосіб оплати й керування PRO — Freemius Checkout + підписані Freemius Webhooks.** Старий сценарій PrivatBank/Monobank «Конверт» та ручне блокування/розблокування більше не використовуються.

## Як працює оплата

1. Адміністратор налаштовує для кожної послуги Freemius Product ID, Plan ID та Product Secret Key.
2. Користувач переходить за посиланням Freemius Checkout і завершує покупку на стороні Freemius.
3. Freemius надсилає підписаний webhook на індивідуальний URL послуги.
4. Pay Box перевіряє HMAC-SHA256 підпис, продукт, план і подію ліцензії.
5. Ліцензія стає активною тільки після підтвердженої події Freemius. Скасування, видалення, деактивація, завершення, повернення коштів або програний спір відкликають доступ.
6. Плагіни перевіряють ліцензію за email покупця або за ліцензійним ключем. Дані різних послуг ізольовані за Service ID.

## Налаштування Freemius

У **UA Pay Box → Послуги → Редагувати** заповніть:

- **Freemius Product ID**;
- **Freemius Plan ID**;
- **Freemius Checkout URL** (можна залишити порожнім — URL буде сформовано з Product ID та Plan ID);
- **Freemius API Bearer Token** — потрібен лише для синхронізації ціни;
- **Freemius Product Secret Key** — потрібен для перевірки підпису webhook.

Після збереження скопіюйте **Freemius Webhook URL** і додайте його в Webhooks відповідного продукту Freemius. Увімкніть події:

- `license.created`
- `license.activated`
- `license.updated`
- `license.extended`
- `license.shortened`
- `license.plan.changed`
- `license.cancelled`
- `license.deactivated`
- `license.expired`
- `license.deleted`
- `payment.refund`
- `payment.dispute.closed`
- `payment.dispute.lost`
- `payment.dispute.won`

Невідомі події ігноруються та не можуть самостійно активувати PRO. Для відкликання ліцензії Pay Box зберігає її статус у таблиці Freemius; сам ключ у відкритому вигляді не зберігається.

## Перевірка ліцензій

**UA Pay Box → Ліцензії Freemius** показує отримані ліцензії, email покупця, ID, термін дії та статус. Старі записи кліків/оплат через Конверт не керують доступом.

Основний REST endpoint:

`POST /wp-json/upb/v1/license/status`

Поля форми:
- `service` — slug або ID послуги;
- `email` — email покупця Freemius; або
- `license_key` — ключ Freemius.

Відповідь містить `valid`, `status`, `expires` та спосіб перевірки. Для зовнішніх клієнтів доступний згенерований код WordPress/PHP, Android/Kotlin і Chrome Extension/JavaScript.

## Безпека

- Product Secret Key зберігається тільки на сервері.
- Webhook приймається лише з правильним HMAC-SHA256 підписом.
- Ліцензії розділені за Service ID.
- PRO не активується від натискання кнопки покупки, відкриття Checkout або повернення на сайт — потрібен webhook Freemius.
- Відсутність зв’язку з Pay Box не повинна трактуватися клієнтським плагіном як активна ліцензія.

## Оновлення

Репозиторій: [portallcomua/UA-Pay-Box](https://github.com/portallcomua/UA-Pay-Box). Релізи містять ZIP для оновлення через адмінку WordPress.
