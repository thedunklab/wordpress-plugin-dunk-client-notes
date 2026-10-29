# DUNK Client Notes

_by DUNK_ · font **DM Sans** (file incluso in `assets/fonts/`, licenza OFL: nessuna richiesta a Google Fonts).

Note e commenti del cliente direttamente sulle pagine dello **staging**, senza strumenti esterni né account.

## Installazione in un progetto
Il plugin vive in questa repo; nei progetti entra come **sottomodulo git** nella cartella dei plugin, così ogni progetto fissa la versione che usa.

```bash
git submodule add git@github.com:thedunklab/wordpress-plugin-dunk-client-notes.git wp-content/plugins/dunk-client-notes
git commit -m "Aggiunge dunk-client-notes"
```

Dopo il clone di un progetto: `git submodule update --init`. Per passare a una nuova versione:

```bash
cd wp-content/plugins/dunk-client-notes && git fetch --tags && git checkout v1.1.0
cd - && git add wp-content/plugins/dunk-client-notes && git commit -m "Aggiorna dunk-client-notes a v1.1.0"
```

Poi attiva il plugin e, solo sullo **staging**, accendilo in Note cliente → Impostazioni. Ogni sito ha le sue impostazioni (nome progetto, destinatari, SMTP, persone esterne).

## Installazione e aggiornamenti senza git (siti dove i plugin non sono versionati)
1. **Prima installazione**: scarica lo zip dell'ultima versione da DUNK Tools (pagina *DUNK Client Notes*, già con la cartella giusta) oppure con `git archive --prefix=dunk-client-notes/ -o dunk-client-notes.zip vX.Y.Z`, e caricalo da Plugin → Aggiungi → Carica plugin. La cartella deve chiamarsi `dunk-client-notes` (lo zip «Download ZIP» di GitHub ha un altro nome di cartella).
2. Da lì in poi le nuove versioni compaiono tra gli aggiornamenti dei plugin (controllo ogni 12 ore, o «Controlla ora» in Note cliente → Novità). Per averle in automatico: «Attiva aggiornamenti automatici» sulla riga del plugin. La repo è pubblica: non serve nessun token.

Se un progetto usa il sottomodulo git, gli aggiornamenti da bacheca sono spenti (decide git). Attenzione: un `deploy` che copia il sottomodulo sullo staging riporta il plugin alla versione fissata nel progetto.

## Sviluppo e versioni
- Si modifica **qui** (o dal sottomodulo di un progetto, poi commit e push nella sua cartella), mai copiando file a mano tra progetti.
- Versioni semver: aggiorna `Version` nell'header di `dunk-client-notes.php` e `DCN_VERSION`, annota in `CHANGELOG.md`, poi `git tag vX.Y.Z && git push origin HEAD:main --tags` (il tag è ciò che i siti vedono come «nuova versione»).

## Cosa fa
- Alla prima apertura del pannello si sceglie **chi sei**: il proprio nome dall'elenco delle persone esterne e il gruppo di lavoro (il browser lo ricorda; «Cambia» in cima al pannello). Chi non è in elenco si aggiunge con nome, cognome ed email e resta «da confermare» finché il team non lo conferma in **Note cliente → Persone esterne**.
- Il cliente clicca **Note → Nuova nota**, sceglie l'elemento della pagina, scrive e invia (solo col pulsante: Invio non invia). Si salvano: pagina, elemento (selettore CSS e punto cliccato), larghezza dello schermo, autore, gruppo e testo.
- **Screenshot automatico** (opzionale, attivo di default) della parte visibile della pagina nel momento del clic, con la puntina numerata sul punto scelto. Header fissi e menu sticky compaiono dove si vedevano. Usa `html2canvas-pro` (incluso, MIT) e si salva in `uploads/dunk-notes/`.
- Sulla pagina compaiono le **puntine** numerate, il **pannello** con l'elenco della pagina e di tutto il sito, la conversazione (il cliente e il team rispondono nella stessa nota).
- **Gruppi di lavoro** (Impostazioni → Gruppi di lavoro): nome e colore liberi per ogni sito (es. Marketing, Regolatorio). Chi scrive sceglie il suo gruppo (il browser lo ricorda) e la puntina prende quel colore; lo stato resta riconoscibile: *in lavorazione* con l'anello bianco, *risolta* sbiadita con la spunta verde. Senza gruppo le puntine sono gialle DUNK (aperta gialla, in lavorazione nera, risolta verde).
- **Correzione del testo**: finché la nota è aperta, chi ha scritto una nota o una risposta la può correggere con «Modifica» dallo stesso browser (che conserva una chiave ricevuta all'invio); chi è nel team può correggere tutto. Il testo corretto è segnato «modificata».
- **Chiusura con avviso**: quando il team mette una nota «In lavorazione» o «Risolta» (dal pannello o dalla bacheca) sceglie chi avvisare via email tra le persone della rubrica, con un messaggio facoltativo. Sono già proposti chi ha scritto la nota o ha risposto e chi era stato segnalato.
- **Bacheca → Note cliente**: elenco con filtri per stato e pagina, scheda della nota con screenshot, risposte, cambio stato, eliminazione, link "Apri la pagina nel punto della nota". Email al team a ogni nuova nota o risposta del cliente.
- **Avviso «Note pronte»** (per pagina, non per nota): nel pannello del cliente (non compare a chi è loggato nel team, che è chi lo riceve), tab «Pagina», il pulsante **Segnala note pronte** manda *una* email alle persone scelte in **Impostazioni → Avviso «Note pronte»** (uno o più utenti WordPress), con l'elenco delle note aperte e i link. Dopo l'invio il pannello mostra «Avviso inviato a … · data · N note»; si può rinviare (con conferma se non ci sono nuove note), le nuove sono marcate «(nuova)» nell'email. Stessa pagina: almeno 2 minuti tra due invii. Senza destinatario scelto il pulsante non compare.
- **Invio email via SMTP** (Impostazioni → Invio email): host, porta, utente, password e mittente (es. MailerSend); con il pulsante «Invia email di prova». Serve sullo staging, dove la posta locale non è autenticata. Vale per tutte le email del sito.
- **Persone esterne**: l'icona accanto a «Pagina / Tutte» apre la rubrica (nome e cognome + email, con «+»). Scrivendo una nota o una risposta si possono scegliere (chip «Segnala a», max 5): ognuno riceve una email con il testo, lo screenshot (solo per le note) e il link. Il link richiede la password dello staging, da comunicare a parte. La stessa rubrica si gestisce in bacheca: **Note cliente → Persone esterne**, dove solo un amministratore può rimuovere le persone.

## Uso
1. Copia la cartella in `wp-content/plugins/` e attiva il plugin.
2. **Note cliente → Impostazioni → Attiva** (solo sullo staging). In alternativa in `wp-config.php`: `define('DUNK_NOTES_ENABLED', true);` (la costante ha la precedenza e blocca l'interruttore).
3. Il cliente apre il sito dello staging (già protetto da password) e vede il pulsante **Note** in basso a destra. Aggiungi `?dcn=off` all'indirizzo per nasconderlo.
4. A fine revisione: disattiva (o rimuovi) il plugin prima di andare in produzione. Con le note attive le pagine sono `noindex`.

## Aspetto
Colori **DUNK**: giallo `#fee000` e nero `#0a0a0a` (più il verde per le risolte) (puntine, pulsanti, pannello, bacheca), apposta diversi dal viola del sito per non confondere le note con il design. Stati: aperta = giallo, in lavorazione = nero, risolta = verde `#4ade80`. L'interfaccia sta in uno Shadow DOM e non eredita gli stili del sito.

## Sicurezza e privacy
- Chi scrive non ha un account: la chiave `X-DCN-Token` (derivata dai salt del sito) viene stampata nelle pagine, che sono già dietro la password dello staging. Le scritture sono limitate (40 ogni 10 minuti per IP), i testi a 2000 caratteri e lo screenshot a 2,5 MB, solo JPEG validato.
- I redattori loggati scrivono come «team»; solo loro cambiano lo stato.
- Tutti i dati restano nel database e in `uploads/` del sito: nessuno script o servizio di terzi. Il browser del cliente conserva solo il nome (localStorage).

## Limiti noti
- Le puntine si agganciano all'elemento con un selettore CSS: se la struttura della pagina cambia o l'elemento è in un carousel/animazione, la puntina passa a una posizione approssimata (bordo tratteggiato).
- Lo screenshot riproduce la pagina con un motore di rendering a parte (html2canvas-pro): font web, filtri o contenuti di altri domini possono differire leggermente.
