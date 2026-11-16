# Registro delle modifiche

## 1.0.1
- Corretto: nella scheda Connessione il campo Utente stava più in basso del campo Password (la nota sotto la password allungava la riga). Ora i campi affiancati sono allineati in alto.

## 1.0.0
- Prima versione completa: invio SMTP di tutte le email del sito, servizi già pronti (posta dell'hosting, Aruba, Register.it, Gmail/Google Workspace, Microsoft 365, Brevo, SendGrid, Mailgun, Amazon SES, IONOS), mittente forzato con return-path e "Rispondi a" al mittente originale.
- Email di prova con messaggi d'errore comprensibili e dialogo col server (senza password).
- Registro degli invii con filtri, ricerca, dettaglio, anteprima dell'email e rinvio (se il testo è salvato); pulizia automatica dopo 30 giorni.
- Avvisi quando le email non partono (Bacheca, pagina Plugin, email di riserva al massimo ogni 6 ore) e invio di riserva facoltativo col server del sito.
- Password cifrata, oppure dati in wp-config.php (`LU3G_MAIL_*`).
- Interfaccia LU3G animata; nel menu "LU3G" se c'è LU3G Hub. Aggiornamenti dalle release di GitHub.
