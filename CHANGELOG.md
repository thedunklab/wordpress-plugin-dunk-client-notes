# Changelog

## 1.10.0 — 2026-10-29
- **Il team scrive come team**: chi è loggato in WordPress non sceglie più un gruppo del cliente; scrive con l'etichetta del team (Impostazioni → **Nome del team**, di base «DUNK»), con puntine nere e numero giallo. Anche le note del team già scritte passano a questa etichetta.
- **Gruppo predefinito «Cliente»**: un sito senza gruppi propri usa il gruppo «Cliente» (giallo, modificabile in Impostazioni). Con un solo gruppo il cliente non deve scegliere nulla e il filtro «Solo …» non compare; le note del cliente scritte prima dei gruppi risultano «Cliente».

## 1.9.2 — 2026-10-29
- Nel riquadro «Nuova nota» la casella dello screenshot si chiama «Cattura uno screenshot automatico» (non «Allega»): lo fa il plugin, non serve caricare un file.

## 1.9.1 — 2026-10-29
- Nel piede del pannello il logo DUNK è a sinistra e a destra compaiono il nome del plugin e la versione installata.

## 1.9.0 — 2026-10-29
- **«Chi sei?» una volta sola**: alla prima apertura del pannello si sceglie il proprio nome dall'elenco delle persone (non più un testo libero), poi il gruppo. Il browser lo ricorda; in cima al pannello «Scrivi come … · gruppo — Cambia». Nome e gruppo spariscono dai moduli di nota e risposta.
- **Non sono in elenco**: chi non c'è si aggiunge con nome, cognome ed email e può scrivere subito; resta «da confermare» finché il team non lo conferma o lo rifiuta in **Note cliente → Persone esterne** (avviso in bacheca, contatore nel menu, email al team). Anche le persone aggiunte dalla rubrica del pannello da chi non è nel team restano da confermare.
- **Filtri** sotto «Nuova nota», su una riga con il conteggio: «Risolte» (mostra anche le note risolte) e «Solo …» con il proprio gruppo, che mostra nell'elenco e sulla pagina solo le note del gruppo (il browser lo ricorda).
- **Niente invio con Invio**: i moduli partono solo dal pulsante, spento finché il testo è vuoto; nelle aree di testo Invio va a capo.
- Modulo di risposta più semplice: testo → «+ Segnala a qualcuno» (chiuso finché non lo apri) → Rispondi.
- Scheda della nota e risposte con la stessa intestazione: nome, gruppo, data, «Modifica» a destra; per il team i pulsanti di stato restano subito sotto la nota.

## 1.8.0 — 2026-10-29
- **Gruppi di lavoro** con colore (Impostazioni → Gruppi di lavoro): chi scrive sceglie il gruppo, la puntina sul sito e quella nello screenshot prendono il suo colore. Lo stato si vede da un segno: anello bianco per «In lavorazione», spunta verde per «Risolta».
- **Correzione di note e risposte** già inviate, solo finché la nota è aperta: chi le ha scritte dallo stesso browser, il team sempre. Il testo corretto è segnato «modificata».
- **Avviso alla chiusura**: mettendo una nota «In lavorazione» o «Risolta» il team sceglie chi avvisare via email tra le persone della rubrica (già proposti l'autore e chi era stato segnalato) e può aggiungere un messaggio. Prima l'email partiva solo verso chi era stato segnalato a mano, e di solito non arrivava a nessuno.
- Il campo nome suggerisce le persone della rubrica: così l'autore della nota è collegato alla sua email.
- **Screenshot** fatto nel momento del clic sull'elemento, non all'invio. Header fissi e menu sticky compaiono dove si vedevano: prima finivano fuori dall'immagine e lasciavano in alto una fascia vuota sempre uguale.
- Campi del riquadro «Nuova nota» con lo stesso stile del pannello.

## 1.7.0 — 2026-10-29
- Aggiornamenti da bacheca senza token: la repo del plugin ora è pubblica. Tolto il campo **Impostazioni → Aggiornamenti** e la costante `DUNK_NOTES_GITHUB_TOKEN`; il token salvato dalle versioni precedenti viene cancellato.
- Messaggi più chiari se GitHub limita le richieste o non trova la repo.

## 1.6.0 — 2026-10-28
- Lo screenshot delle nuove note mostra la puntina numerata (stessa forma e colori di quella sul sito) con la punta nel punto indicato dal cliente, al posto del cerchio: se le puntine si spostano, si capisce comunque a cosa si riferiva la nota. Le note già salvate restano com'erano.

## 1.5.2 — 2026-10-28
- Pagina «Novità»: registro delle versioni come elenco leggibile (versione e data in evidenza); se il plugin è aggiornato si legge il registro locale, e una risposta grezza di GitHub in cache non si vede più.

## 1.5.1 — 2026-10-28
- Fix: nella pagina «Novità» il registro delle versioni mostrava la risposta grezza di GitHub invece del testo.

## 1.5.0 — 2026-10-28
- Nuova impostazione «Posizione del pulsante»: lato (destra o sinistra) e distanza dal bordo basso, per non sovrapporsi ad altri widget fissi (Iubenda, chat).
- Il pannello delle note segue la posizione del pulsante.

## 1.4.0 — 2026-10-28
Aggiornamenti da sito a sito e registro delle novità.
- Il plugin si aggiorna dalla bacheca di WordPress (Plugin → «Aggiorna ora», anche automatico) leggendo l'ultimo tag `vX.Y.Z` della repo GitHub: niente più deploy per ogni nuova versione.
- Nuova voce **Note cliente → Novità**: versione installata e disponibile, «Controlla ora», registro delle versioni.
- Il registro compare anche nella scheda «Visualizza dettagli» del plugin (tab Changelog).
- Nuovo campo **Impostazioni → Aggiornamenti** per il token GitHub in sola lettura (oppure costante `DUNK_NOTES_GITHUB_TOKEN` in wp-config.php).
- Nei checkout git (sottomoduli di sviluppo) gli aggiornamenti da bacheca restano spenti.

## 1.3.0 — 2026-10-28
- Nuova impostazione «Dopo l'invio»: il pannello si chiude dopo l'invio di una risposta, così si può scegliere subito un'altra nota (attiva di default).

## 1.2.0 — 2026-10-28
Ancoraggio robusto delle note agli elementi della pagina.
- Alla nota si salvano, oltre al percorso CSS, l'impronta dell'elemento (tag, id, classi stabili, attributi, testo, antenati, fratelli) e un selettore semantico senza `nth-of-type`.
- Prima di posizionare la puntina il candidato è confrontato con l'impronta: se non combacia (es. si è tolto un elemento vicino e gli indici sono scalati) l'elemento si cerca per somiglianza; se non c'è più la nota resta «Elemento non trovato» con posizione approssimata, invece di finire su un altro elemento.
- Il team, aprendo una pagina, aggiorna l'ancoraggio delle note vecchie senza impronta e di quelle ritrovate altrove.

## 1.1.0 — 2026-10-28
Prima versione, estratta da un progetto cliente.
- Avviso «Note pronte» per pagina (una email agli utenti WordPress scelti), con riquadro nel pannello del cliente.
- Invio email via SMTP (es. MailerSend) con email di prova.
- Email HTML e pannello nello stile DUNK Tools (tema nero con dots).
- Rubrica «Persone esterne» (pannello e bacheca; rimozione solo admin) e segnalazione di note e risposte via email.
- Risposta del team facoltativa se la nota è «Risolta» o «In lavorazione».
- Fix: caratteri speciali nei testi, riga «Segnalata a» vuota.

## 1.0.0
Note del cliente sulle pagine dello staging: puntine, screenshot, risposte, stato, bacheca.
