# 🗣️ SHELFY — the pantry assistant

SHELFY is EverShelf's voice (and typed) assistant. It sits in the **centre of the bottom
nav**, wears the project's own avatar (`assets/img/shelfy/`), listens to one sentence and
does one thing — but never without telling you what it is about to do.

It has **no voice of its own**: text-to-speech belongs to the recipe cooking mode. SHELFY
listens and answers on screen.

---

## What it understands

One sentence per tap. The words are **data**, not code: `api/vocab/<lang>.json` → `"capture"`,
so the same Italian sentence works on an English UI.

| You say | What happens |
|---|---|
| `Ho mangiato 1 mela` | The floating confirmation registers 1 piece of the mela in your pantry |
| `Ho usato 300 g di fusilli` | Same, with the grams you said |
| `Ho usato mezza mela` | Fractions (`mezza`, `metà`, `tre quarti`, `½`…) |
| `Ho bevuto 250 ml di latte` | Eat / drink / use / take are all "consumed" |
| `Ho finito il latte` | Opens the product sheet with **Usa tutto** (slide to confirm) |
| `Aggiungi il latte` | Opens the add form with the quantity already set |
| `Aggiungi le uova alla spesa` | Adds a line to the shopping list |
| `Butta il pane` | Opens the discard flow for that row |
| `Cosa posso cucinare oggi?` | Opens the recipe chat with your sentence |
| `Ho mal di testa` | Local symptom map → your medicine cabinet (no AI, no dosage) |

When the name matches more than one row, SHELFY shows **four candidates** with picture,
brand, place and quantity — the closest one is preselected.

---

## The 10-second confirmation

Nothing is written by a sentence alone.

1. The capture sheet closes **on the page you are already on** — no navigation.
2. A card appears with the avatar, what will be registered
   (`Registro 1 pz di Mela rossa`) and a mint bar that drains from right to left.
3. The seconds count down on the card (`Confermo tra 9 s se non tocchi`).
4. When the bar is empty, the quantity is written; the toast confirms it and the
   dashboard refreshes itself.

| Action | Result |
|---|---|
| **Modifica** | The clock stops and the compact product sheet opens at that quantity — the same sheet a tap on the row opens |
| **✕ Annulla** | Nothing is written |
| Touch anywhere else while arming a button | The countdown stops; the decision is yours |

Emptying a row (`Ho finito il latte`) is deliberately **two steps**: it opens the sheet
where *Usa tutto* keeps its slide-to-confirm.

---

## Why it never opens the old full page

Earlier builds handed a spoken sentence to the legacy **Usa / Consuma** page — a
`← Indietro` header, a purple recipe button and a chip that read *Tutte una parte*. On a
kiosk with wet hands that is three taps too many, and it took the user away from the
screen they were looking at. The voice path now stays where it is; the full page is only
reachable from the guided flows that still drive its fields.

---

## Adding words or a language

Never edit `app.js` for a synonym:

1. add the word/phrase to the matching list in `api/vocab/<lang>.json` →
   `"capture"` (`intent`, `numbers`, `units`, `fractions`, `all_words`, `stop_words`);
2. regenerate the browser copy: `php scripts/gen-vocab-js.php`;
3. add the language to `"locale"` if the language is new.

The browser engine (`assets/js/core/vocab.js` → `vocabCaptureLexicon()`) merges the active
language first and then every other shipped language, which is why an Italian sentence is
understood on an English UI.

---

## Privacy

Everything SHELFY parses is local: the vocabulary, the matching and the action. The
microphone belongs to the browser's Speech Recognition and only runs while its sheet is
open — closing the sheet stops it before anything else happens.
