# Regola: Sincronizzare la doc docmd con le feature

La documentazione pubblica vive in `docs-site/` ed è costruita con **docmd**
(vedi la skill `docmd-docs`). Va mantenuta allineata al codice.

## Quando aggiornarla (obbligatorio)

Ogni volta che, nello stesso lavoro/PR:

- si **aggiunge** una feature (metrica, comando Artisan, opzione di config,
  evento, API di report, plugin, profilo di coda, ecc.);
- si **modifica** il comportamento, la firma o i default di una feature esistente;
- si aggiorna il **`README.md`** in modo sostanziale (non typo/formattazione).

Allora, se la feature è user-facing, **aggiorna anche la pagina docmd corrispondente**
in `docs-site/docs/**`. Se non esiste una pagina adatta, creala e aggiungi la sua
voce nel `navigation[]` di `docs-site/docmd.config.json`.

## Cosa NON richiede aggiornamento doc

- refactor interni senza impatto sul comportamento osservabile;
- fix di test o tooling che non cambiano l'uso pubblico;
- modifiche puramente cosmetiche al README.

In questi casi dichiara esplicitamente "nessun aggiornamento docmd necessario"
così la scelta è tracciata.

## Come farlo

1. Usa la skill `docmd-docs` per la sintassi dei container, le icone Lucide,
   i plugin e i gotcha.
2. Mantieni il contratto dati e i nomi coerenti tra codice, README e doc.
3. Prima di chiudere: `cd docs-site && npm run check && npm run build` e verifica
   che la build sia pulita (nessun HTML/MDX raw residuo, `_site/index.html`
   presente, nessun `:::` come testo visibile).

## Anti-pattern

- spedire una feature o un aggiornamento README user-facing senza toccare `docs-site/`;
- aggiungere una pagina senza registrarla nel `navigation[]`;
- reintrodurre sintassi MDX/Mintlify o HTML raw (`<Card>`, `<Note>`, `<br>`, …)
  nei file `.md`.
