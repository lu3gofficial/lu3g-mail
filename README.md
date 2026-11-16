# LU3G Mail

Invia le email del sito WordPress tramite un server SMTP, con l'interfaccia LU3G. Sostituisce WP Mail SMTP sui siti LU3G.

Plugin WordPress di LU3G Agenzia Web. Requisiti: PHP 7.4+, WordPress 6.0+ (consigliato 5.9+ per il registro: usa
l'evento `wp_mail_succeeded`).

## Cosa fa
- **Connessione SMTP** per tutte le email del sito (form, WooCommerce, recupero password...): server, porta,
  cifratura (SSL 465, TLS 587, nessuna), utente e password. Servizi già pronti: posta dell'hosting, Aruba, Register.it,
  Gmail/Google Workspace (password per le app), Microsoft 365 (se l'SMTP autenticato è attivo), Brevo, SendGrid,
  Mailgun, Amazon SES, IONOS.
- **Mittente**: email e nome uguali per tutte le email, return-path al mittente. Quando il mittente viene sostituito
  (es. un form che usa l'email del visitatore) la risposta va comunque al visitatore (Rispondi a).
- **Email di prova** con esito chiaro ("password sbagliata", "porta chiusa", "nome del server inesistente"...) e il
  dialogo col server per il supporto, sempre senza la password.
- **Registro**: data, destinatari, oggetto, esito e motivo dell'errore; facoltativo anche il testo, per rimandare
  un'email. Si cancella da solo dopo i giorni impostati (30 di serie).
- **Avvisi** se le email non partono: in Bacheca, nella pagina Plugin e nel pannello; facoltativo un'email a un
  indirizzo di riserva (al massimo ogni 6 ore). Facoltativo l'invio di riserva col server del sito.
- **Password** cifrata (AES-256, chiave dalle chiavi segrete di `wp-config.php`), oppure tutti i dati in `wp-config.php`:
  `LU3G_MAIL_HOST`, `LU3G_MAIL_PORT`, `LU3G_MAIL_ENCRYPTION` (ssl/tls/none), `LU3G_MAIL_USER`, `LU3G_MAIL_PASS`,
  `LU3G_MAIL_FROM`, `LU3G_MAIL_FROM_NAME`.
- Con **LU3G Hub** attivo la pagina sta nel menu "LU3G" (voce "Email SMTP"), altrimenti ha una voce propria.

## Passare da WP Mail SMTP
1. Installa LU3G Mail (da LU3G Hub o caricando lo zip), ricopia server, porta, utente, password e mittente.
2. Salva e manda un'email di prova. Non c'è un interruttore: appena il server è salvato, le email del sito partono da
   LU3G Mail (senza server, WordPress invia come prima). Per tornare all'invio di WordPress si disattiva il plugin.
3. Se arriva, disattiva WP Mail SMTP (due plugin SMTP attivi insieme si sovrascrivono: vince l'ultimo che configura PHPMailer).

## Struttura
- `lu3g-mail.php` – intestazione e avvio.
- `includes/class-settings.php` – impostazioni, cifratura, costanti di wp-config.php, servizi già pronti.
- `includes/class-mailer.php` – configurazione di PHPMailer, mittente, errori comprensibili, email di prova, riserva, avvisi.
- `includes/class-log.php` – tabella del registro, rinvio, pulizia giornaliera.
- `includes/class-admin.php` – menu, pagina, azioni (admin-ajax), avviso in bacheca.
- `includes/class-updater.php` – **generato** da [lu3g-updater](https://github.com/lu3gofficial/lu3g-updater) (`.lu3g-updater`): non modificarlo a mano.
- `uninstall.php` – all'eliminazione cancella impostazioni e registro.

## Pubblicare una nuova versione
1. Aumenta la versione in `lu3g-mail.php` (`Version:` e `LU3G_MAIL_VERSION`) e `Stable tag` in `readme.txt`.
2. Aggiungi la sezione in `CHANGELOG.md` (diventa la nota della release).
3. Commit, poi `git tag vX.Y.Z && git push && git push --tags`, oppure da GitHub: Actions → Release → Run workflow.

## Crediti
Loghi di Gmail, Brevo, Mailgun e IONOS da [Simple Icons](https://simpleicons.org) (CC0). I marchi appartengono ai
rispettivi proprietari e nel pannello servono solo a riconoscere il servizio.
