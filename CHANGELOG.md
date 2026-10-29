# Changelog

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
