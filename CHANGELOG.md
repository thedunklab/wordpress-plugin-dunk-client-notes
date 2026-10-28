# Changelog

## 1.2.0 — 2026-10-28
Ancoraggio robusto delle note agli elementi della pagina.
- Alla nota si salvano, oltre al percorso CSS, l'impronta dell'elemento (tag, id, classi stabili, attributi, testo, antenati, fratelli) e un selettore semantico senza `nth-of-type`.
- Prima di posizionare la puntina il candidato è confrontato con l'impronta: se non combacia (es. si è tolto un elemento vicino e gli indici sono scalati) l'elemento si cerca per somiglianza; se non c'è più la nota resta «Elemento non trovato» con posizione approssimata, invece di finire su un altro elemento.
- Il team, aprendo una pagina, aggiorna l'ancoraggio delle note vecchie senza impronta e di quelle ritrovate altrove.

## 1.1.0 — 2026-10-28
Prima versione estratta dal progetto Uripyr D.
- Avviso «Note pronte» per pagina (una email agli utenti WordPress scelti), con riquadro nel pannello del cliente.
- Invio email via SMTP (es. MailerSend) con email di prova.
- Email HTML e pannello nello stile DUNK Tools (tema nero con dots).
- Rubrica «Persone esterne» (pannello e bacheca; rimozione solo admin) e segnalazione di note e risposte via email.
- Risposta del team facoltativa se la nota è «Risolta» o «In lavorazione».
- Fix: caratteri speciali nei testi, riga «Segnalata a» vuota.

## 1.0.0
Note del cliente sulle pagine dello staging: puntine, screenshot, risposte, stato, bacheca.
