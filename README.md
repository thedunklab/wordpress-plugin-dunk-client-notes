# DUNK Client Notes

Note e commenti del cliente direttamente sulle pagine dello **staging**, senza strumenti esterni né account.

## Cosa fa
- Il cliente clicca **Note → Nuova nota**, sceglie l'elemento della pagina, scrive e invia. Si salvano: pagina, elemento (selettore CSS e punto cliccato), larghezza dello schermo, nome e testo.
- **Screenshot automatico** (opzionale, attivo di default) della parte visibile della pagina, con un cerchio rosso sul punto scelto. Usa `html2canvas-pro` (incluso, MIT) e si salva in `uploads/dunk-notes/`.
- Sulla pagina compaiono le **puntine** numerate (colore per stato: aperta, in lavorazione, risolta), il **pannello** con l'elenco della pagina e di tutto il sito, la conversazione (il cliente e il team rispondono nella stessa nota).
- **Bacheca → Note cliente**: elenco con filtri per stato e pagina, scheda della nota con screenshot, risposte, cambio stato, eliminazione, link "Apri la pagina nel punto della nota". Email al team a ogni nuova nota o risposta del cliente.

## Uso
1. Copia la cartella in `wp-content/plugins/` e attiva il plugin.
2. **Note cliente → Impostazioni → Attiva** (solo sullo staging). In alternativa in `wp-config.php`: `define('DUNK_NOTES_ENABLED', true);` (la costante ha la precedenza e blocca l'interruttore).
3. Il cliente apre il sito dello staging (già protetto da password) e vede il pulsante **Note** in basso a destra. Aggiungi `?dcn=off` all'indirizzo per nasconderlo.
4. A fine revisione: disattiva (o rimuovi) il plugin prima di andare in produzione. Con le note attive le pagine sono `noindex`.

## Sicurezza e privacy
- Chi scrive non ha un account: la chiave `X-DCN-Token` (derivata dai salt del sito) viene stampata nelle pagine, che sono già dietro la password dello staging. Le scritture sono limitate (40 ogni 10 minuti per IP), i testi a 2000 caratteri e lo screenshot a 2,5 MB, solo JPEG validato.
- I redattori loggati scrivono come «team»; solo loro cambiano lo stato.
- Tutti i dati restano nel database e in `uploads/` del sito: nessuno script o servizio di terzi. Il browser del cliente conserva solo il nome (localStorage).

## Limiti noti
- Le puntine si agganciano all'elemento con un selettore CSS: se la struttura della pagina cambia o l'elemento è in un carousel/animazione, la puntina passa a una posizione approssimata (bordo tratteggiato).
- Lo screenshot riproduce la pagina con un motore di rendering a parte (html2canvas-pro): font web, filtri o contenuti di altri domini possono differire leggermente.
