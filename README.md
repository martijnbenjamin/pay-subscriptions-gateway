# PAY Subscriptions Gateway voor WooCommerce

WooCommerce betaalgateway voor [PAY.nl](https://www.pay.nl) met ondersteuning voor automatische incasso via mandaten, webhooks en abonnementen via WooCommerce Subscriptions.

Ontwikkeld door [Martijn Benjamin](https://martijnbenjamin.nl) — webdesigner en WordPress-specialist, eigenaar van [072DESIGN](https://072design.nl).

---

## Wat doet deze plugin?

Deze plugin integreert PAY.nl als betaalmethode in WooCommerce en voegt ondersteuning toe voor:

- Automatische incasso via betaalmandaten (iDEAL, SEPA Direct Debit)
- Terugkerende betalingen via WooCommerce Subscriptions
- Webhook-verwerking met HMAC-SHA256 verificatie
- Opslaan van betaalmandaten als WooCommerce Payment Tokens
- Terugbetalingen via de PAY.nl API
- Testmodus via de PAY.nl sandbox

De plugin is gebouwd voor eigen gebruik en openbaar gemaakt zodat anderen er gebruik van kunnen maken of op voort kunnen bouwen.

---

## Vereisten

- WordPress 5.6 of hoger
- PHP 7.4 of hoger
- WooCommerce 6.0 of hoger
- [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) (voor terugkerende betalingen)
- Een actief [PAY.nl](https://www.pay.nl) account met Direct Debit ingeschakeld

---

## Installatie

1. Download of kloon deze repository
2. Upload de map naar `/wp-content/plugins/pay-subscriptions/`
3. Activeer de plugin via **WordPress → Plugins**
4. Ga naar **WooCommerce → Instellingen → Betalingen**
5. Activeer **PAY (Subscriptions)** en klik op **Instellingen**

---

## Configuratie

### Vereiste instellingen

| Instelling | Formaat | Waar te vinden |
|---|---|---|
| PAY Token Code | `AT-xxxx-xxxx` | PAY.nl dashboard → API tokens |
| PAY API Token | — | PAY.nl dashboard → API tokens |
| PAY Service ID | `SL-xxxx-xxxx` | PAY.nl dashboard → Services |

De webhook secret wordt automatisch gegenereerd bij activatie.

### API-permissies

Zorg dat je API token de volgende permissies heeft in PAY.nl:

- Transactions: Create, Read
- Mandates: Create, Read, Cancel
- Refunds: Create
- Services: Read

### Webhook instellen in PAY.nl

1. Ga naar **PAY.nl → Services → [jouw service] → Webhooks**
2. Voeg een nieuwe webhook toe:
   - **URL:** `https://jouwsite.nl/?wc-api=pay_subs_webhook`
   - **Events:** Transaction status changes, Mandate changes, Refunds
   - **Secret:** kopieer de gegenereerde secret uit WooCommerce

---

## Testen

Schakel testmodus in en gebruik de PAY.nl sandbox credentials. Doorloop de volgende stappen:

1. Maak een testproduct met een abonnement aan
2. Plaats een bestelling in testmodus
3. Volg de redirect naar PAY.nl en rond de testbetaling af
4. Controleer of de orderstatus bijgewerkt is naar `processing` of `complete`
5. Test webhook events via de PAY.nl webhook tester
6. Forceer een abonnementsverlenging via WooCommerce en controleer of het mandaat wordt gebruikt

Logs zijn te vinden via **WooCommerce → Status → Logs** (filter op `pay_subscriptions`). Schakel debug logging in bij de gateway-instellingen voor uitgebreide output.

---

## Veelvoorkomende problemen

**Betaling mislukt**
Controleer of de API credentials correct zijn en of de service actief is in PAY.nl. Bekijk de logs voor specifieke API-foutmeldingen.

**Webhook werkt niet**
Verifieer de webhook URL en secret in het PAY.nl dashboard. Test via de PAY.nl webhook tester en controleer of de server geen 401 of 500 teruggeeft.

**Abonnementsverlenging faalt**
Controleer of het mandaat correct is opgeslagen als WooCommerce Payment Token en of Direct Debit is ingeschakeld voor de service.

---

## Bijdragen

Verbeteringen, bugfixes en pull requests zijn welkom. Houd rekening met het volgende:

- De plugin is primair gebouwd voor de eigen setup van 072DESIGN en kan afwijken van jouw configuratie
- Er is geen garantie op ondersteuning of updates
- **Gebruik op eigen risico** — test altijd grondig in een testomgeving voordat je dit in productie zet

---

## Changelog

### v1.7.0
- Abonnementsverlenging werkt nu met iDEAL als initiële betaalmethode
- IBAN-opslag vanuit initiële iDEAL-betaling voor SEPA Direct Debit
- Meervoudige renewal-methode: payment token → SEPA-mandaat → orderreferentie
- API-fallback wanneer webhook-data onvolledig is

### v1.6.0
- Stabiele productieversie
- Volledige namespace implementatie (`PAY_Subscriptions`)
- PAY.nl REST API v1 (connect.payments.nl)
- HMAC-SHA256 webhook verificatie
- Mandate opslag als WooCommerce Payment Tokens
- Uitgebreide logging en foutafhandeling

---

## Licentie

GPLv2 or later — zie [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

---

Gemaakt door [Martijn Benjamin](https://martijnbenjamin.nl) · [072DESIGN](https://072design.nl) · Noord-Holland
