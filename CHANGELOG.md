# Registro delle modifiche

## 1.1.0
- Niente più interruttore "Invia tramite SMTP": con il plugin attivo tutte le email del sito passano dall'SMTP appena il
  server è salvato. Senza server WordPress invia come prima (un sito appena installato non resta senza email), e lo stato
  in alto dice "Da configurare". Per tornare all'invio di WordPress si disattiva il plugin. Anche i siti dove
  l'interruttore era spento, se hanno un server salvato, passano all'SMTP.
- Schede dei fornitori con loghi e icone al posto delle iniziali: loghi ufficiali di Gmail, Brevo, Mailgun e IONOS da
  Simple Icons (licenza CC0); icone disegnate per Altro server, Posta dell'hosting, Aruba, Register.it, Microsoft 365,
  SendGrid e Amazon SES, che su Simple Icons non hanno il logo. Ogni icona è in bianco sul colore del fornitore.

## 1.0.2
- Punteggio antispam migliore per **tutte** le email HTML del sito (form, ordini, email di prova):
  - se manca la cornice `<html><body>` viene aggiunta (SpamAssassin HTML_MIME_NO_HTML_TAG, −0,6 punti su mail-tester);
  - se manca la versione in testo semplice viene ricavata dall'HTML, con i link come "testo (indirizzo)" (MIME_HTML_ONLY); il messaggio diventa multipart/alternative come con WP Mail SMTP. La versione in testo scritta da un plugin, se c'è, resta quella.
- Il sito si presenta al server SMTP col dominio del mittente invece del nome interno dell'hosting (SPF_HELO_NONE).
- Email di prova: "server, porta 465" invece di "server:465", che i filtri leggevano come un indirizzo web su una porta strana (WEIRD_PORT).

## 1.0.1
- Corretto: nella scheda Connessione il campo Utente stava più in basso del campo Password (la nota sotto la password allungava la riga). Ora i campi affiancati sono allineati in alto.

## 1.0.0
- Prima versione completa: invio SMTP di tutte le email del sito, servizi già pronti (posta dell'hosting, Aruba, Register.it, Gmail/Google Workspace, Microsoft 365, Brevo, SendGrid, Mailgun, Amazon SES, IONOS), mittente forzato con return-path e "Rispondi a" al mittente originale.
- Email di prova con messaggi d'errore comprensibili e dialogo col server (senza password).
- Registro degli invii con filtri, ricerca, dettaglio, anteprima dell'email e rinvio (se il testo è salvato); pulizia automatica dopo 30 giorni.
- Avvisi quando le email non partono (Bacheca, pagina Plugin, email di riserva al massimo ogni 6 ore) e invio di riserva facoltativo col server del sito.
- Password cifrata, oppure dati in wp-config.php (`LU3G_MAIL_*`).
- Interfaccia LU3G animata; nel menu "LU3G" se c'è LU3G Hub. Aggiornamenti dalle release di GitHub.
