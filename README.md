# PAY.nl Subscriptions Gateway voor WooCommerce

## Verbeteringen in deze versie

### Gefixte problemen:
1. **Namespace probleem opgelost** - Alle classes hebben nu correct de `PAY_Subscriptions` namespace
2. **Beschadigde gateway class hersteld** - De dubbele code en syntax fouten zijn verwijderd
3. **Complete API implementatie** - De PAY.nl REST API v2 is nu volledig geïmplementeerd
4. **Verbeterde error handling** - Uitgebreide logging en foutafhandeling toegevoegd
5. **Webhook verificatie** - Correcte signature verificatie geïmplementeerd
6. **Token/mandate opslag** - Proper implementatie voor het opslaan van betaalmandaten

## Installatie

1. Upload de plugin naar `/wp-content/plugins/pay-subscriptions-fixed/`
2. Activeer de plugin via het WordPress admin panel
3. Ga naar WooCommerce → Instellingen → Betalingen
4. Activeer "PAY (Subscriptions)" gateway
5. Configureer de instellingen

## Configuratie

### Vereiste instellingen:

1. **PAY API Token** (verplicht)
   - Format: `AT-xxxx-xxxx`
   - Te vinden in je PAY.nl account onder API tokens

2. **PAY Service ID** (verplicht)
   - Format: `SL-xxxx-xxxx`
   - Te vinden in je PAY.nl account onder Services

3. **Webhook Secret**
   - Wordt automatisch gegenereerd bij activatie
   - Gebruik deze URL in PAY.nl: `https://jouwsite.nl/?wc-api=pay_subs_webhook`

4. **Testmodus**
   - Schakel in voor testen met de PAY.nl sandbox
   - Gebruik test API credentials

## PAY.nl Configuratie

### Webhook instellen:

1. Log in op je PAY.nl account
2. Ga naar Services → Jouw service → Webhooks
3. Voeg nieuwe webhook toe:
   - URL: `https://jouwsite.nl/?wc-api=pay_subs_webhook`
   - Events: Transaction status changes, Mandate changes, Refunds
   - Secret: Kopieer de secret uit WooCommerce settings

### API Permissies:

Zorg dat je API token de volgende permissies heeft:
- Transactions: Create, Read
- Mandates: Create, Read, Cancel
- Refunds: Create
- Services: Read

## Debugging

### Logs bekijken:

1. Schakel "Debug Logging" in bij de gateway settings
2. Logs zijn te vinden in: WooCommerce → Status → Logs
3. Zoek naar logs met source `pay_subscriptions`

### Veelvoorkomende problemen:

#### "Betaling mislukt" error:
- Check of API credentials correct zijn
- Verifieer dat de service actief is in PAY.nl
- Controleer de logs voor specifieke API errors

#### Webhook werkt niet:
- Verifieer webhook URL in PAY.nl dashboard
- Check of webhook secret overeenkomt
- Test met PAY.nl webhook tester
- Controleer server logs voor 401/500 errors

#### Subscription renewal faalt:
- Check of mandate correct is opgeslagen
- Verifieer dat klant een actief mandate heeft
- Controleer of Direct Debit is geactiveerd voor je service

### Test flow:

1. **Eerste betaling testen:**
   ```
   - Maak test product met subscription
   - Plaats bestelling in testmodus
   - Gebruik PAY.nl test credentials
   - Volg redirect naar PAY.nl
   - Complete test betaling
   - Check of order status updated naar "processing/complete"
   ```

2. **Webhook testen:**
   ```
   - Gebruik PAY.nl webhook tester
   - Stuur test event naar je webhook URL
   - Check WooCommerce logs
   - Verifieer order status update
   ```

3. **Recurring payment testen:**
   ```
   - Forceer subscription renewal via WooCommerce
   - Check logs voor mandate gebruik
   - Verifieer dat renewal order wordt aangemaakt
   ```

## Technische details

### Ondersteunde features:
- WooCommerce Subscriptions
- Automatische incasso via mandates
- Payment method updates
- Refunds
- Multiple subscriptions per klant
- Subscription suspension/reactivation
- Webhook verificatie met HMAC-SHA256

### API Endpoints gebruikt:
- `POST /v2/transactions/start` - Nieuwe transacties
- `GET /v2/transactions/{id}` - Status opvragen
- `POST /v2/transactions/{id}/refund` - Terugbetalingen
- `POST /v2/mandates/create` - Mandate aanmaken
- `POST /v2/mandates/{id}/cancel` - Mandate annuleren

### Database:
- Mandates worden opgeslagen als WooCommerce Payment Tokens
- Transaction IDs worden gekoppeld aan orders
- Webhook events worden gelogd

## Support

Voor problemen of vragen:
1. Check eerst de logs in WooCommerce
2. Verifieer PAY.nl dashboard voor transaction details
3. Test met debug mode aan voor uitgebreide logging

## Changelog

### Version 0.2.0 (Fixed)
- Complete rewrite van gateway class
- Implementatie PAY.nl REST API v2
- Verbeterde webhook handler
- Fix voor namespace problemen
- Uitgebreide error handling
- Betere mandate/token opslag
- Support voor subscription updates

### Version 0.1.0 (Original)
- Initial release

## Licentie

GPL v2 or later
