# local_artqtml Backlog

> **ArtQTML Light note:** This backlog is largely historical (pre-fork / Full-era). Light ships
> IH+FE+SR, paste+TXT, thin admin — no PDF/DOCX, Bloom, FT/EH/RV, or `.lic`. Prefer open Light
> work items and shared-bugfix sync with Full; treat PDF/license/Bloom entries as archive.

> **This file is the single source for the backlog.** It used to exist twice: here, and as a
> BL-01…BL-11 table restated in every session handover. Nothing kept the two in agreement, which
> is the same two-source shape this project removed from the specification (markdown → docx) and
> the test register (markdown → xlsx). From 2026-07-28, a session handover **links here** and does
> not copy the list; an item is added, changed or closed in this file only.
>
> BL numbers are stable and never reused, so older documents that cite one still resolve.

> **Completed 2026-07-25:** all three god-class refactors done — see
> `docs/technikai_melleklet_v11.md` section 11 for the new architecture.

## Open

### BL-60 — Negative fractions on incorrect FT (multi-answer) options — OPEN · Full / premium

**Raised 2026-08-10 by András — backlog only; not Free/Light.** Product placement: Full
(master / premium) only. Light keeps today's behaviour (incorrect FT options at fraction `0`).

**The problem.** For multi-answer (`FT` / `qtype_multichoice` multi), correct options share `1.0`
and incorrect ones are imported as `0.0`. Selecting every option can therefore still score
**100%**. Moodle's usual fix is a **negative fraction** on each incorrect option so that
"tick all" cannot yield full marks.

**What to build (when scheduled).**

1. **Admin setting** — opt-in (or configurable) negative penalty for incorrect FT options.
2. **Import path** — `question_importer` / `apply_multichoice` (and any fraction normaliser) must
   write those negative fractions when the setting is on, not leave incorrect options at `0`.
3. **Keep FE alone** — single-answer multi-choice stays as today; only multi-correct FT needs this.

**Out of scope for this entry:** implementing the setting or changing Light. Tracked so the
freemium cut and the Full feature list stay aligned (`artqtml-freemium-cut` canvas: Full-only).

### BL-46 — Tell the teacher when a question is one they have already seen — DEFERRED

**Designed 2026-08-03, deferred the same day by András: a good feature, but the product works
without it.** Written down in full so it does not have to be re-derived; everything below is
measured, not proposed.

**Why it exists.** A teacher generates six questions, keeps two, regenerates for more, and gets
back the four they just rejected. Nothing on the screen says why. Three measurements on
2026-08-03 established that this cannot be fixed on the generating side - see BL-37 - so the
remaining move is to *tell* the teacher instead of pretending to fix it.

**The size of the phenomenon: 110 of 181 measured questions are a repeat of an earlier one - 61%.**
Every question was read and grouped by which fact it asks for with which expected answer, giving 71
distinguishable groups.

**What the teacher would see.** In the *Validációs javaslat* column - the one that never collapses
at any screen width, and where a second, action-free marker already lives (the approver's name,
Jov-046) - under the validator's badge:

```
[Módosítandó]                                        the existing verdict, untouched
[Ismétlődő kérdés]                                   the new marker
Hasonló ehhez: ALMA1-RV-0003 (Alma alapok, 07-28)    a link
```

The second line is the point. A bare "duplicate" label cannot be checked; **naming the earlier
question** makes it checkable, and the link opens the same preview the row's own action does. The
count summary above the table gains one line ("of these, duplicates: 4") so the size of the job is
visible before the teacher starts down the list.

Nothing new to learn: look at the pair, then either tick and delete, or ignore and approve. All
three actions exist today.

**What it is compared against: every question made from the same source text.** This is measured,
not assumed. Of the 110 real repeats:

| where the pair was | count |
|---|---:|
| **in the same generation** | **0** |
| in an earlier generation | **110** |

A within-generation check would find nothing - a model does not repeat itself inside one response.
The damage happens on the *next* generation. Not scoped to the user, because the tool is site-wide
by design (Glob-031) and "a colleague already asked this" is a real case.
`local_artqtml_generations.sourcetexthash` is already indexed, so this is one cheap query. On a
source text's first generation the marker never fires, which is correct - there is nothing to repeat
yet.

**When it runs: the save step, not the validation step.** At validation the questions still sit in
`pendingdata` and some are yet to be discarded by the semantic check; at save it is known which
survive, and the rows are written in one transaction. No AI call, so the effect on generation time
is not measurable against a pipeline already measured in minutes.

**How the comparison works.** Character 4-gram Jaccard similarity over
`question text + " || " + correct answer`. Not an AI call: the text comparison reaches 96%
precision on the measured data, an AI judgement would cost per question, and it would expose the
marker to the same variability the same day's measurements were trying to pin down.

`duplicate_detector`'s machinery is reusable, **its parameters are not**: its 5-word shingles over a
12-word median question can only ever yield 0 or 1, and 39% of the answers are shorter than five
words. Character n-grams also survive Hungarian suffixes (*pektin* / *pektinben*).

**András's rule - "same question text but different answers means a new question" - needed one
correction, and the data forced it.** For multiple choice, **only the correct option is compared,
not the distractors**:

> *Melyik növénycsaládba tartozik az almafa?* — correct: *Rózsafélék családja* — distractors:
> Pillangósvirágúak / Keresztesvirágúak / Fészkesvirágzatúak
>
> *Melyik növénycsaládba tartozik az almafa?* — correct: *A rózsafélék családjába* — distractors:
> pillangósvirágúak / **fűfélék** / **boglárkafélék**

Read literally the answers differ, so it is a new question. To the teacher it is the complaint
itself. What a question *is* comes down to what you have to know, and that is the correct answer.

The rule does hold literally where it matters, and the veto keeps it:

> *Az alma beporzását leginkább a szél, és nem a rovarok végzik.* → **Hamis**
> *Az alma beporzását leginkább a méhek és más megporzó rovarok végzik.* → **Igaz**

Two questions, not one, and the rule does not flag them.

Per type, what stands in for "the answer": IH the boolean; FE the correct option's text; FT the set
of correct options; SR the items in the schema's order; RV the word; **EH the grader info** - not an
answer, but the only field that says what the question expects, and in the data it works (the
question text alone does not reveal that two essays ask for the same four facts).

**The working rule.** A question is a duplicate if there is an earlier question of the same type
from the same source text where both hold: similarity **≥ 0.30**, and **no answer conflict** -
IH the booleans differ, or FE/FT/RV the correct answers' n-gram overlap is under 0.5, means *not* a
duplicate however similar the wording. No veto for SR and EH: their wording varies enough that the
veto cut real duplicates (measured: SR misses rose from 2 to 5 with it on).

0.30 is not a guessed number: the 95th percentile of *unrelated* pairs sits between 0.12 and 0.20
per type, related pairs' median between 0.36 and 1.00 - it falls in the gap.

**What it catches, on 181 real questions:**

| | flagged | real | false | missed | precision | recall |
|---|---:|---:|---:|---:|---:|---:|
| `duplicate_detector`'s current parameters | 32 | 32 | 0 | 78 | 1.00 | **0.29** |
| **the proposed rule** | 105 | **101** | **4** | 9 | **0.96** | **0.92** |

**Erring towards flagging, and only because the marker triggers nothing.** A false flag costs the
teacher a few seconds; a miss leaves them exactly where they are today, so it costs nothing new.
That asymmetry holds on one condition: **no automatic deletion, no blocking, and not a third
validator verdict** - a separate, independent marker in its own field, as András asked. One of the
four false flags is, by his own rule, genuinely a new question; automatic deletion would lose a good
question silently.

**Where it goes in the code.** Not into `generation_status` - that is deliberately the single source
of the seven *generation*-level statuses (List-018). The question-level equivalents are
`validation_suggestion.php` and `problem_category.php`, both under `classes/local/` with the same
shape (`VALUES`, `normalise()`, `label()`, `badge_class()`). A third alongside them:
`classes/local/duplicate_status.php`, two values (`unique`, `duplicate`) - no "not assessed" third
state, because this is not an AI verdict: either the check ran, or there was nothing to compare
against, and both mean `unique`. The table needs a flag, a reference to the pair, and the similarity
value, following the existing `validationsuggestion` / `problemcategory` columns.

**Cost, measured in Python on the real questions:** 2,000 stored questions is **33 ms**. The plugin
already does **39 ms** of the same kind of work on *every upload* - and that runs while the teacher
waits in the browser. This would run in the background, after minutes of AI calls.

**What the design does not know:** there is no untruncated measurement data for **FT** (multiple
answer) - the matrix runs produced none of that type (BL-30) and the 2026-08-03 grid's export is
truncated. The rule is proposed as identical to FE, unmeasured.

### BL-03 — Handle HTTP 429 separately in the backoff — DEFERRED

**Deferred by András, 2026-08-02.** Downgraded once the paid tier was in use; its precondition
(V-06) is met. It waits for rate limiting to become visible again, and this entry records what to do
when it does, so the next reader does not have to re-derive it.

**What the code does today.** `retry_trait` puts 429 in the same bucket as a server fault:

```
RETRYABLE_HTTP      = [429, 500, 503, 504, 529]
MAX_HTTP_ATTEMPTS   = 3
MAX_BACKOFF_SECONDS = 7.2      // 2 s, then 4 s, each with up to 20% jitter
```

A 500 or 503 means *something hiccupped, try again* - two seconds is a sensible answer. **A 429 means
something else: you are going too fast**, and the provider says for how long, in the response's
`Retry-After` header.

**The plugin never looks at response headers.** `ai_request::send()` returns exactly three things:

```php
return ['httpcode' => …, 'body' => …, 'curlerror' => …];
```

So "wait 30 seconds" arrives in every rate-limited response and is discarded.

Three consequences follow:

- **The wait is guaranteed to be too short.** Rate-limit windows are typically measured in minutes;
  the whole retry budget is 7.2 seconds. A real limit burns all three attempts inside the window.
- **Retrying fast can extend the problem** - a refused request still counts against the limit.
- **It compounds BL-26.** A failed call discards questions already generated and paid for. That was
  seen twice on 2026-08-02, from timeouts rather than 429s, but a rate limit lands in the same place.

**The trap to know before touching it:** `MAX_BACKOFF_SECONDS` is not documentation.
`process_pending_generations` sizes its `set_time_limit()` from it. If 429 gets longer waits, that
constant has to move with them, or PHP kills the task mid-wait.

### BL-05b — Bump the GitHub actions from `@v5` to `@v7`, and protect `main` — DEFERRED

**Deferred by András, 2026-08-06.** It was never actionable without the company git account, and
carrying it as "waiting on András" put it in the same list as work that could start today. The
three Dependabot PRs stay open; the entry below is unchanged and holds the reasoning for whoever
picks it up.

Was "not urgent" while the repository was private and single-owner, because the fork-PR vector did
not apply. **The move to a company git account removes that reasoning** — v7's headline change is
exactly the fork-PR security default. Re-evaluate as part of the move, not before.

Dependabot has already opened the PR for it (#1, `actions/checkout` 5 → 7, CI green), alongside #2
(`actions/cache` 4 → 6) and #3 (an npm dev dependency). They sit open on purpose until this is
decided.

**Branch protection belongs to the same decision, 2026-08-02.** GitHub offers `main` protection as
one button, but it covers two things of very different weight:

- **Force push and deletion.** Nothing in this workflow force-pushes, so turning it on costs
  nothing and prevents the one mistake whose history cannot be recovered. Worth doing whenever.
- **Required status checks before merging.** This one changes how the work is done: today the
  pushes go straight to `main` and CI runs *after*. Requiring checks means branch → PR → green →
  merge. That is a working-method decision, not a security setting, and it belongs with the move to
  the company account, where more than one person pushes.

Worth recording as the argument *for* it, from the day this was written: nothing was pushed for a
whole working day, so CI said nothing about the code for that whole day — and then sixty-three files
went up at once. A PR-shaped round would have broken that into pieces CI could speak about.

### BL-26 — A validator outage throws away questions that were already generated and paid for — DEFERRED
Found 2026-07-31 while testing the rewritten prompt. The pipeline is generate -> validate -> save.
On 2026-07-31 the generate call succeeded twice, HTTP 200 on the first attempt each time, and both
runs then failed at 50% because Gemini returned 503 "This model is currently experiencing high
demand" three times in a row.

**The generated questions existed at that moment.** `generate_questions_task` writes them into the
generation's `pendingdata` on success; they only reach `local_artqtml_questions` after validation.
So the approve page says "No questions have been generated" while the JSON is sitting in the row.

**Retry discards them.** `status.php`'s `local_artqtml_rollback()` clears in-flight pipeline data,
and the confirmation dialog says so plainly: *"This will call the AI service again from scratch."*
The user is told the truth; what the dialog cannot show is that the first call was already billed.
A transient outage in the second provider therefore costs a second full generation from the first.

**Not a bug - a missing path.** There is no "resume from validation" route, and adding one is a real
decision, not a patch: it needs a way to tell a resumable failure from one where the pending data
should not be trusted, and `pendingdata` is deliberately left in place after a failure so
`status.php` can work out which stage was reached.

**Worth weighing against its cost:** this only bites when the two providers fail independently,
which is exactly what happened twice tonight.

**Deferred by András, 2026-08-02, with the cost recorded rather than argued away.** It happened twice
more that day, in the BL-32 grid: `M32-FT-S3` and `M32-SR-S2` both died on the identical
`Operation timed out after 60005 milliseconds with 0 bytes received` from Gemini, after Claude had
already produced and billed for six questions each. Both had to be re-run from scratch, which means
two Claude calls were paid for twice.

So the measured rate is **2 failures in 36 generations, about 6%**, and each one costs a full
generation's worth of tokens. That is the number to weigh when this comes back up - and the trigger
for picking it up is either production use, where somebody else pays that 6%, or a rise in the rate. Whoever picks it up should also check whether the
retry could offer the choice rather than decide it - "retry validation only" beside "start again".

## Closed

### BL-56 — A szövegkinyerő végpont korlátai — CLOSED 2026-08-06, MÉRÉSSEL ELINTÉZVE, nem kóddal

**A tétel négy tanári problémát írt le, és a mérés mind a négyet megszüntette. Egyetlen sor kód nem
készült hozzá — és ez a helyes kimenet.**

A záró mérés a legnagyobb valódi tananyagon, amit ma a kezünkbe kaptunk: `IDD_1_0228_képekkel.pdf`,
**1,07 MB, 21 oldal, negyvennél több képpel**, ötször egymás után hívva:

| | 1. hívás | 2–5. hívás |
|---|---:|---:|
| 21 oldalas PDF, 16 119 karakter | **72 ms** | 16 / 15 / 19 / 16 ms |
| 5–7 oldalas DOCX / TXT (korábbi mérés) | 24 / 14 ms | 8–9 ms |

**Ötvenszeres fájlméret, háromszoros oldalszám — és még mindig század másodperc alatt.**

**Mi lett a négy problémából:**

1. **„A tanár mozdulatlan képernyőt lát."** Nincs mit jelezni: a válasz hamarabb megjön, mint hogy
   bárki észrevenné a hiányát.
2. **„Az újrapróbálkozás megduplázza a terhelést."** 16 millisecundumot duplázunk. A `contenthash`
   alapú gyorsítótár többe kerülne karbantartásban, mint amennyit megspórol.
3. **„Egy tanár nagy fájlja a kollégái óráját is lassítja."** Tíz egyszerre feltöltő tanár együtt
   nagyjából **egyharmad másodpercnyi** szervermunkát okoz. Nincs mit korlátozni.
4. **„Aki kiváltja, jóhiszemű."** Tárgytalan, ha nincs mit kiváltani.

**AMI EBBŐL TANULSÁG, és ez marad meg a tételből:** ezt az egészet **kódolvasásból vezettem le** —
láttam, hogy nincs gyorsítótár, nincs korlát, nincs töltésjelzés, és ebből építettem négy tanári
problémát, sorrenddel és javaslatokkal. A hiányzó szám egyetlen mérés volt, és az a mérés a tétel
háromnegyedét elvitte. **A „nincs benne védelem" nem ugyanaz, mint a „baj van vele".**

**Ami szándékosan NEM tartozik ide:** a dekompressziós bomba elleni védelem. Az nem sebesség
kérdése, és megvan — a BL-49 és a BL-50 erőforrás-korlátai (64 MiB forrásfájl, 192 MiB kicsomagolt
összméret, 100-szoros tömörítési arány) épp ezt fogják. Ez a tétel a hétköznapi tananyagok
sebességéről szólt, és arról kiderült, hogy nincs miről.

**Ez a tétel a lezárt BL-50 második lépése, saját bejegyzést kapva.** Ott a szövege a *Closed*
szakaszban élt, ahol nem keresi senki — a BL-50 olcsó méretellenőrzése elkészült, ez nem. A sorrend
akkor is szándékos volt: a méretellenőrzés önmagában sokat visz, a korlát és a gyorsítótár csak
utána indokolt.

**Amit a kód mond, ellenőrizve és nem átvéve:** a `local_artqtml_extract_text` webszolgáltatásnak
**nincs** felhasználónkénti hívásszám-korlátja, **nincs** párhuzamossági korlátja, és a
`text_extractor`-ban meg a végponton **egyetlen gyorsítótár sincs** (a `db/caches.php` nem ismer
ilyet). Minden hívás újrafuttatja a teljes feldolgozást, a nulláról.

**Amit egyetlen hívás elvihet, a mai konstansok szerint:** 64 MiB forrásfájl, 192 MiB kicsomagolt
összméret, 16 MiB egyetlen adatfolyamra. Ez a **felső határ**, nem a tipikus eset — de ez az, amit
egy hívás megkérhet, és ebből tetszőleges sok futhat egyszerre.

---

**A tanár szemszögéből ez négy különböző dolog, és csak az egyik látszik hibának.**

**1. A tanár nem tudja megkülönböztetni a lassút a bedőlttől.** A feltöltés után a kinyerés a
háttérben fut, és a felületen **semmi nem jelzi, hogy dolgozik** — a modulban nincs töltésjelzés,
nincs letiltott gomb, nincs felirat. *(A JS olvasásából tudom, nem mértem: a próbáim kis fájlokkal
mentek, ahol a válasz azonnal jött.)* A tanár egy mozdulatlan képernyőt lát. A természetes reakció
erre az, hogy még egyszer megpróbálja — ami a 2. pontot hozza.

**2. Az újrapróbálkozás nem olcsóbb, hanem ugyanannyi.** Nincs gyorsítótár, tehát ugyanannak a
fájlnak a második feltöltése **ugyanazt a munkát végzi el újra**. Aki türelmetlen, vagy akinek a
böngészője újraküldi a kérést, az nem várakozik hosszabban — hanem megkétszerezi a szerver
terhelését. A jelenlegi felület ezt egyenesen bátorítja, mert nem mondja meg, hogy fut.

**3. Egy tanár nagy fájlja a többi tanár óráját is lassítja.** Párhuzamossági korlát nélkül tíz
egyszerre feltöltő tanár tíz teljes kinyerést indít, egyenként a fenti plafonokkal. Ez nem a plugin
lassulása, hanem a **Moodle-é**: ugyanazokat a PHP-folyamatokat foglalja, amikből a kollégák
kurzusoldalai is élnek. Aki emiatt vár, az nem is tudja, hogy egy kérdésgenerálás miatt vár.

**4. Aki ezt kiváltja, jóhiszemű.** A végpont `local/artqtml:use` jogosultságot kér, tehát tanár
hívja, nem a nyílt internet. Ehhez nem kell rosszindulat: elég egy hétfő reggeli tanári értekezlet
után egyszerre nekiálló tanszék.

---

**Mi változna a tanárnak, ha ez elkészül:**

- **Látná, hogy dolgozik.** Ez a legolcsóbb fele, és önmagában is megéri: egy jelzés a mező mellett,
  amíg a válasz meg nem jön.
- **A második feltöltés azonnal jönne.** A `contenthash` alapú, rövid idejű gyorsítótár ugyanarra a
  fájlra ugyanazt a szöveget adná vissza újraszámolás nélkül — ugyanaz a fájl ugyanazt a szöveget
  adja, tehát ez nem közelítés.
- **Terhelés alatt értelmes választ kapna**, nem egy időtúllépést: „most sokan töltenek fel, próbáld
  meg egy pillanat múlva" — ez megmondja, mit tegyen, egy megszakadt kérés nem.

#### A MÉRÉS MEGTÖRTÉNT, 2026-08-06 — és megfordítja a tétel sorrendjét

A hiányzó szám megvan. Egy ~21 400 karakteres, 5–7 oldalas magyar tananyag, három alakban, a
`local_artqtml_extract_text` végpont hívásának ideje a böngészőből mérve, négyszer egymás után:

| formátum | fájlméret | kinyert karakter | 1. hívás | 2–4. hívás |
|---|---:|---:|---:|---:|
| TXT | 23,7 KB | 21 383 | **14 ms** | 9 / 9 / 9 ms |
| DOCX | 38,7 KB | 21 333 | **24 ms** | 8 / 8 / 8 ms |
| PDF | 35–63 KB | — | elutasítás, lásd **BL-59** | — |

**A PDF sor még hiányzik, és most már megmérhető.** A méréskor a plugin mindkét PDF-et
elutasította; a BL-59 aznap este ezt megjavította (21 589 és 21 568 karakter), tehát a négyszeri
hívás PDF-fel is elvégezhető. **Amíg nincs meg, ennek a tételnek a nagyságrendre vonatkozó
következtetése csak a TXT-re és a DOCX-re áll** — a PDF-út összehasonlíthatatlanul több munkát
végez (objektumtérkép, oldalbejárás, glifatáblák), és nincs rá számunk.

**Ez a tétel három feltevéséből kettőt megdönt.**

1. **„A tanár mozdulatlan képernyőt lát, mert sokáig tart."** Nem tart sokáig: **25 ms alatt** van.
   A töltésjelzés így nem a várakozás ellen kell — hanem legfeljebb azért, mert a mező néma marad.
   Sokkal kisebb haszon, mint amit a tétel eredetileg tulajdonított neki.
2. **„Az újrapróbálkozás megduplázza a terhelést."** Igaz, de a duplázandó mennyiség 9 ms. A
   `contenthash` alapú gyorsítótár így **nem éri meg a karbantartását** ezen a nagyságrenden.
3. **Ami áll:** a párhuzamossági korlát kérdése — de nem ezekre a méretekre. Egy 64 MiB-os,
   sok száz oldalas PDF a plafonja ennek az útnak, és arról továbbra sincs mérésem.

**A mérés korlátai, hogy ne hivatkozzon rá senki többre, mint amennyit ér:** egy gépen, egy
fájlon, üresjáratban, localhoston futott, PHP-profilozás nélkül; a hálózati út benne van a
számban. A tanulság nem az, hogy „9 ms", hanem hogy **ez a nagyságrend, nem a másodperceké**.

**A tétel új sorrendje ebből:** a gyorsítótár és a töltésjelzés lekerül a lista elejéről; ami marad,
az a **nagy fájlok** párhuzamossági kérdése, és az is mérés után.

**Amit ez a tétel a mérés előtt nem tudott:** nem volt adata arról, **mennyi ideig tart** egy
kinyerés egy hétköznapi tananyagon. A BL-48 mérése (egy 47 KB-os PDF-ből 4768 karakter) a helyességről szólt, nem
az időről. Az első lépés tehát nem kód, hanem **egy mérés**: hétköznapi PDF, DOCX és TXT kinyerési
ideje. Enélkül a korlát számai találgatások lennének — és ez a projekt már állított be egyszer
olyan korlátot (256 archívum-bejegyzés, 32 MiB), ami valódi tananyagot utasított volna vissza.

**Egy szempont a sorrendhez:** a három közül a **töltésjelzés** az, amit egy ránézés eldönt és
azonnal használ a tanárnak; a gyorsítótár utána jön; a hívásszám- és párhuzamossági korlát pedig
mérés után, mert annak a számai nem tippelhetők.


### BL-59 — A Word/LibreOffice PDF-exportjából a plugin nem nyer ki szöveget — CLOSED 2026-08-06

**A képernyőn ellenőrizve, 2026-08-06, NÉGY fájllal — kettő generált, kettő valódi tananyag.**

| a PDF | korábbi mérés | most, a Forrásszöveg mezőben |
|---|---:|---:|
| LibreOffice Writer exportja | 0 (`notext`) | **21 568 karakter** |
| reportlab | 0 (`notext`) | **21 589 karakter** |
| **`A körte.pdf`** — valódi Word-export, a BL-48 fájlja | **4 768** (2026-08-04) | **4 768 karakter** |
| **`IDD_1_0228_képekkel.pdf`** — 21 oldalas PowerPoint-export, a BL-48 kiinduló fájlja | **64** (2026-08-04, javítás előtt) | **16 119 karakter** |

**A negyedik sor zárja le a BL-48 történetét is.** Az volt a fájl, amiről a BL-48 megnyílt: 21 oldal
biztosítási jogi tananyag, amiből a plugin **64 karaktert** adott. A BL-48 Python-prototípusa
16 033 visszanyerhető karaktert jósolt; a plugin ma **16 119-et** ad — a referenciaparser (pypdf)
16 110-et lát ugyanezen a fájlon. A szöveg helyes: a fejezetcím, a szereplőlista és a jogi rész is
benne van, ékezethelyesen.

**A harmadik sor a regressziós biztosíték:** ami 2026-08-04-én működött, ma **karakterre ugyanannyit**
ad. `\001`-féle törmelék egyik fájlban sincs.

**AMIT EZ NEM BIZONYÍT, hogy senki ne vegyen ki belőle többet:** a szkennelt, csak képet tartalmazó
PDF továbbra sem olvasható, ez tervezett (nincs OCR). Az `/LZWDecode` szűrős, régebbi PDF kimarad a
kezelt körből — tudott korlát, nem mért hiány.

**Az ok megvan, mérve. Három külön hiba, három külön helyen, egy fájlban sem egyedül.**

#### Ahogy a nyomozás ment, mert a módszer maga is eredmény

PHP nem futtatható ebben a környezetben, ezért a `text_extractor` PDF-útját és a `local\pdf\`
osztályait **Pythonban írtam újra, karakterről karakterre ugyanazokkal a reguláris kifejezésekkel
és ugyanazzal a zlib-kicsomagolással** — ugyanaz a fogás, ami a BL-48-at is megoldotta. A mása
**pontosan reprodukálta a hibát** (0 karakter mindkét fájlra), és lépésenként ki lehetett íratni,
hol áll meg. Az első futás egy sorban megadta a választ:

```
oldal obj 3: 1 fontabla [F2: tabla kesz, 128 bejegyzes, 1 bajtos kodok]
             | 0 tartalomfolyam, 0 bajt | mapped 0, unmapped 0 | 0 karakter
```

**Az objektumtérkép jó volt, az oldalak megvoltak, a `/ToUnicode` táblák felépültek — és nulla
tartalom-adatfolyam jutott el az olvasóig.** A gyanú (a BL-48 tétele szerint a `/ToUnicode` vagy a
hex/literál operandusok) mindkét irányban téves volt: a hiba **három lépéssel odébb**, a tömörítés
visszabontásánál állt.

#### Az első ok — a `/Filter` egy **lista**, sorrenddel (ReportLab)

A page-objektum `/Contents 15 0 R` hivatkozása jó, a 15-ös objektum megvan, az adatfolyam megvan.
A szótára viszont ez:

```
/Filter [ /ASCII85Decode /FlateDecode ] /Length 2242
```

Négy helyen ugyanaz a kérdés állt a kódban: `strpos($object, 'FlateDecode') !== false`. Ez nem azt
kérdezi, hogy „zlib-tömörített-e ez az adatfolyam", hanem azt, hogy „szerepel-e valahol a szótárban
a *FlateDecode* szó". Az ASCII85-páncélt viselő bájtok így egyenesen a `gzuncompress`-be mentek, az
elutasította, az adatfolyam kimaradt. **A mért fájl mind az öt oldala így készült**; a fájl összes
`/Filter`-e megszámolva: öt darab `[ /ASCII85Decode /FlateDecode ]`, kettő `[ /FlateDecode ]`.

#### A második ok — a `/Length` lehet **közvetett hivatkozás** (LibreOffice Writer)

```
2 0 obj
<</Length 3 0 R/Filter/FlateDecode>>
stream
```

A `stream_data()` mintája `/\/Length\s+(\d+)\b/` volt, ami ezt a **hármas számnak** olvasta: az
`endstream`-ig érő 3 441 bájtból **három bájt** ment a zlibhez. A LibreOffice azért ír így, mert egy
tömörített adatfolyam hosszát csak a végén tudja. **A mért fájl tizennégy `/Length` mezőjéből tíz
közvetett** — köztük minden oldal tartalma.

Ez a hiba **a 2026-08-04-i `/Length`-javítás mellékhatása**: az a változás tette a `/Length`-t
mértékadóvá a sortörés-levágás helyett (és jogosan: egy bájt hiánya akkor egy egész glifatáblát
vitt el), csak nem nézte meg, hogy a `/Length` nem mindig szám.

#### A harmadik ok — az oktális szökés, és ez a legcsendesebb

Az első kettő javítása után a ReportLab-fájl **28 507 karaktert** adott 21 589 helyett:

```
Bevezet\001s a n\002v\001nytanba \003 tananyag a 9. \001vfolyam sz\004m\004ra
```

A `unescape_pdf_string()` hat `str_replace`-t futtatott (`\(`, `\)`, `\\`, `\n`, `\r`, `\t`) — az
oktális `\ddd` alakot **nem ismerte**. Egy alkészlet-betűtípus kódjai 1-től indulnak, a PDF pedig
minden nem nyomtatható bájtot oktálisan ír ki, tehát egy ilyen oldal szinte teljes egészében
`\001\002\003`. Feloldatlanul **négy** bájt ment a glifatáblába a visszaper, a nulla, a nulla és az
egyes kódjaként, és egy teljes, 128 bejegyzéses tábla **mind a négyet ismeri** — ezért a
`mapped`/`unmapped` számláló is hibátlanra állt (21 077 / 0). **Nem üres eredmény, hanem több a
kelleténél. Egyetlen létező jelzés nem szólt.**

Ugyanez a sorrendben futó `str_replace`-sor egy másikat is elrontott: a `\(` szabály előbb sült el,
mint a `\\`, ezért a `\\(` — szabályos PDF-sztring — egyetlen `(`-re esett össze. A feloldás
mostantól **egy menetben** fut.

#### A mérés a javítás után

| fájl | előtte | utána | pypdf (viszonyítás) |
|---|---:|---:|---:|
| `tananyag_kicsi.pdf` (ReportLab, 5 oldal) | **0** | **21 589** | 21 334 |
| `lo/tananyag_kicsi.pdf` (LibreOffice, 7 oldal) | **0** | **21 568** | 21 541 |
| `tananyag.pdf` (ReportLab, 16 oldal) | **0** | **69 620** | 68 801 |

Az eredeti `.txt` 21 383 karakter. Szóközre normalizálva a **ReportLab-fájl a forrásszöveget
karakterre pontosan adja vissza** (21 333 = 21 333, a forrás teljes egészében benne van); a
LibreOffice-é három karakterrel tér el, mert a Writer a `szén-dioxidból` szót sortöréssel vágta
ketté, és ez a saját tördelése, nem kinyerési hiba.

#### Amit módosítottam

- **új:** `classes/local/pdf/stream_filter.php` — a `/Filter` lánc sorrendhelyes visszabontása
  (`/FlateDecode`, `/ASCII85Decode`, `/ASCIIHexDecode`). Bármi más `null`, amire a hívó az
  adatfolyam kihagyásával válaszol; korábban a nem kezelt szűrőjű adatfolyam **nyers bájtjai
  átmentek a szövegoperátor-olvasón**, tehát egy JPEG belsejét olvastuk tananyagként.
- `pdf/object_index.php` — a `stream_data()` új, elhagyható `?object_index $index` paramétere
  követi a közvetett `/Length`-t. **Ha nem tudja követni, a régi, sortörést levágó közelítés fut,
  nem a hivatkozás első száma** — ez a fix másik fele, és ezt külön teszt őrzi.
- `pdf/content_stream_reader.php` — a `unescape_pdf_string()` egy menetben fut, és feloldja az
  oktális szökéseket, a `\b`/`\f`-et és a sortörés-folytatást.
- `local/text_extractor.php` — mind a három kicsomagoló helye a `stream_filter`-t hívja.
- verzió: `2026080607` → `2026080608`.

#### Amit teszt fed

- **Két valódi fájl fixture-ként:** `tests/fixtures/pdf/reportlab-ascii85.pdf` és
  `libreoffice-indirect-length.pdf`, adatszolgáltatós teszttel
  (`test_a_word_processor_pdf_yields_its_whole_text`). Az állítások: `STATUS_OK`, 21 000 karakter
  felett, két ékezetes magyar mondattöredék benne van, **`\0` nincs benne** (ez fogja meg az
  oktális hibát), és a kimenet érvényes UTF-8. **Valódi fájl kellett hozzá:** mind a három hibát
  átengedte volna kézzel írt fixture, mert a kézzel írt fixture ugyanazokat a feltevéseket hordozza,
  mint a kód, amit tesztel.
- `tests/local/pdf/stream_filter_test.php` (új, 8 eset): a kétlépcsős lánc sorrendben, a
  névsorrend, az egynevű alak, a szűrő nélküli adatfolyam változatlansága, a nem kezelt szűrő
  `null`-ja, a sérült zlib `null`-ja, az ASCIIHex páratlan záró számjegye.
- `object_index_test.php` +2 eset: a közvetett `/Length` követése, és a nem követhető `/Length`
  visszaesése a levágásra.
- `content_stream_reader_test.php` +3 eset: az oktális szökés, a `\\(`, a sortörés-folytatás.

**A kapukat nem futtattam** — ebben a környezetben nincs PHP. A phpcs, a PHPStan és a PHPUnit
futtatása hátravan.

#### A BL-49 2026-08-05-i döntése — a tétel ezt gyanúsította, és a mérés felmenti

A tétel eredeti bejegyzésének **legsúlyosabbnak** nevezett pontja az volt, hogy a 2026-08-05-i
döntés (a bejárható, de betűt nem adó szerkezet **elutasítás** lett a régi, teljes fájlos olvasásra
való **visszalépés** helyett) tette nullává ezeket a fájlokat: „azelőtt valamennyi szöveget
adhattak". A bejegyzés maga is jelezte, hogy ez **következtetés, nem mérés**.

**Megmértem, mert enélkül a lezárás egy meg nem nézett történetet örökített volna tovább.** A régi,
teljes fájlos ágat is újraírtam a Python-másban, a javítás előtti állapotban, és ráfuttattam:

| fájl | amit a visszalépés adott volna |
|---|---:|
| `tananyag_kicsi.pdf` (ReportLab, 7 adatfolyam) | **0 karakter** |
| `lo/tananyag_kicsi.pdf` (LibreOffice, 14 adatfolyam) | **0 karakter** |

**A döntés semmibe nem került ezeken a fájlokon.** És az ok mindkettőnél megnevezhető: a
ReportLab-fájl öt oldal-adatfolyama ott is ASCII85-páncélos, tehát ugyanúgy kimaradt volna; a
LibreOffice-é pedig `[<...>] TJ` hexadecimális, amit a teljes fájlos ág **betűtípus-tábla nélkül**
olvas, és a tábla nélküli hexadecimális kód **szándékosan** nem ad semmit (különben zajt adna).

**A gyanú tehát téves volt, és a javítás sem a döntést vonja vissza.** A `notext` termékdöntésként
áll: a tanár megtudja, hogy nem sikerült. A `dontesek.md`-be ebből nem megy semmi.

#### Ami nyitva marad

- **Képernyős ellenőrzés a localhoston** (lásd az átadó jegyzőkönyvet): a két PDF feltöltése a
  feltöltő oldalon, és a magyar szöveg megjelenése a forrásszöveg mezőben.
- **A BL-56 időmérésének PDF sora** üresen maradt, mert a fájlokat akkor még elutasította a plugin.
  Most mérhető: ugyanaz a négyszeri hívás TXT/DOCX mellé PDF-fel.
- **A `/Filter` kezelt köre** három névre szűkül. Egy `/LZWDecode`-os régi PDF ma `null`-t ad, tehát
  kimarad — ez tudott korlát, nem mért hiány: ilyen fájlom nem volt.

### BL-58 — A validátor a nyers szöveget bírálta el, a tanár a megtisztítottat kapta — CLOSED 2026-08-06

**András vette észre, 2026-08-06, a BL-55 képernyős ellenőrzésének eredményén** — azon a képernyőn,
amit én bizonyítékként mutattam fel. A megállapítás az volt, hogy *a komment a formázásra jön, nem
a kérdésre*.

**Mi történt.** A folyamat sorrendje **generálás → validálás → mentés**. A BL-55 a lecsupaszítást a
**mentéshez** tette (`question_form_builder`). A validátor tehát a **nyers** szöveget kapta, és arra
a formázásra költötte az ítéletét, amit a tanár soha nem fog látni.

A tanárnál ez így nézett ki a 2026-08-06-i mérésen: a kérdés tiszta, mellette **„Módosítandó"**, az
indoklás pedig *„a kérdés felesleges és zavaró vizuális formázást (kék háttérszínt)"* tartalmaz — egy
kék háttérről, ami már nincs sehol. **A BL-55 megoldott egy problémát, és csinált egy másikat, ami
addig nem létezett:** azelőtt a formázás legalább ott volt, amiről a panasz szólt.

**Ez nem a mérés műterméke.** A mérésen kikényszerítettem a formázást, de ugyanez történik magától
is, valahányszor a modell `<b>`-t vagy `<p>`-t ad: a validátor leminősíti a kérdést, és a tanár egy
nem létező hibáról kap üzenetet, amivel nem tud mit kezdeni.

**Amit a hiba megmutat a módszerről, és ez a maradandóbb rész.** A BL-55-öt a **kódút** mentén
ellenőriztem — hol hívódik a tisztító, mit ad vissza —, nem a **folyamat** mentén: mi történik a
modell válaszával a generálástól a tanár képernyőjéig. A tesztek zöldek voltak, a képernyő jó volt,
és a hiba pont a kettő között, a validáláson múlt.

#### A javítás

A lecsupaszítás átkerült a **feldolgozási lépésbe** — oda, ahol a modell válasza először adattá
válik (`generate_questions_task`, a JSON értelmezése után, minden korai visszatérés **előtt**).
Onnantól a validátor, a tárolt `questiondata`, a jóváhagyó képernyő és a kérdésbank **ugyanazt a
szöveget** látja, és az az a szöveg, amit a tanár is látni fog.

Új osztály: `local\question\ai_text_cleaner`, `clean()` és `clean_question()` metódussal. A
`question_form_builder` továbbra is hívja minden mezőre — **a tisztítás idempotens**, tehát a
mentésnél lévő második kör nem ront semmit, és fedi azokat az utakat, amik nem a feldolgozási
lépésen jönnek (mindenekelőtt a változás előtt írt `pendingdata`).

**A mezőlista egy helyen van, nem hatfelé típusonként.** A `clean_question()` a nyolc szöveges
mezőt, az opciók `text`/`explanation` párját és az SR-elemeket járja végig — a gépi értékeket
(`correct`, `correctanswer`, `difficulty_label`, `source_reference`, `type`) **szándékosan nem
érinti**. Egy minden sztringen végigmenő tisztító pontosan azt az adatot rontaná el, aminek a
védelmére bekerült; erre külön teszt van.

**Négy új teszt**, köztük az **idempotencia** — ez az, ami a két kört biztonságossá teszi —, és az,
hogy a gépi értékek érintetlenül jönnek ki.

#### A képernyőn ellenőrizve, 2026-08-06 — ugyanazzal a szándékosan előállított formázással

Ugyanaz a prompt-kiegészítés, ugyanaz a forrásszöveg, egy igaz/hamis generálás. **A kettő
összehasonlítása a bizonyíték**, nem az egyik önmagában:

| | a validátor indoklása a jóváhagyó lapon |
|---|---|
| **BL-58 előtt** (1588) | *„A kérdés felesleges és zavaró **vizuális formázást (kék háttérszínt)**, valamint egy a témához nem kapcsolódó második mondatot…"* |
| **BL-58 után** (1589) | *„A kérdés két teljesen különálló állítást von össze egyetlen Igaz/Hamis kérdésben (az almafa beporzása és a víz kémiai képlete), ami megtévesztővé…"* |

A formázás a második indoklásból **eltűnt** — a lapon a `formáz`/`háttér`/`szín`/`kék` szavak egyike
sem fordul elő. Ami megmaradt, az egy **valódi tartalmi kifogás** arról a kérdésről, amit a tanár
tényleg lát: két különálló állítás egy igaz/hamis kérdésben. Ezzel a tanár tud mit kezdeni.

A kérdés maga változatlanul tiszta — a natív előnézetben:

```
Az almafa beporzását elsősorban a szél végzi, nem a méhek és más rovarok. A víz képlete H<sub>2</sub>O.
```

Se `style`, se `<b>`, a `<sub>` megmaradt. A prompt visszaállítva az eredetire és visszaolvasva
(381 karakter, a mérési utasítás nélkül).

**Kapuk:** phpcs `No errors`, PHPUnit **367 teszt / 2375 assertion**, verzió `2026080607`.

**Dokumentáció, 2026-08-06 este pótolva** — és a pótlás maga is megállapítás: ez a tétel a nap
végéig **dokumentálatlan maradt**, mert magam csináltam. Amit ügynökre bíztunk, annak volt
dokumentációja; amit magam, annak nem. Most a specifikáció **Gen-041** sora és a technikai melléklet
**6.7.a** fejezete írja le. Ugyanebben a körben került be a fájlválasztó alatti jegyzet javítása is
(**Felt-045**): a sikeres feltöltésnél a lap addig azt állította, hogy „a feltöltött fájl figyelmen
kívül marad", miközben a tanár épp látta a fájl szövegét megjelenni a mezőben.

### BL-57 — Egy tanárnak egyszerre egy generálása futhasson — CLOSED 2026-08-06

**András kérése, 2026-08-06.** Ma egy tanár tetszőleges sok generálást tehet a sorba, és a
`process_pending_generations` **limit nélkül** kéri le az összes függőben lévőt (`timecreated ASC`),
majd **egyetlen futásban, egymás után** dolgozza fel mindet, a PHP időkorlátot a sor hosszához
méretezve. Nem párhuzamosság tehát, hanem **sorbanállás**: aki egy nagy munka mögé kerül, kivárja.

---

#### 1. Kinek a korlátja — **András döntése, 2026-08-06: azé, aki a gombot megnyomja**

A `generate.php` **nem tulajdonos-ellenőrzött**: a `Glob-031` szerint a `local/artqtml:use`
jogosultság elég **bármelyik** generálás megnyitásához és indításához; a tulajdonost egy figyelmeztető
sáv mutatja, nem hozzáférési korlát. A „tanár" tehát kétértelmű volt, és a kérdés eldőlt: **a keret
azé, aki a Generálás indítása gombot megnyomja.** A tétel eredeti szövege a rekord létrehozóját
javasolta; ezt a bekezdés felülírja.

Ebből következik, hogy az **indítás beírja a `userid`-t** az indítót végző felhasználóra, ugyanabban
a lépésben, amelyik a `generating` státuszt beállítja — a korlát ezen az oszlopon számol, tehát a
kettőnek egyeznie kell. (E nélkül egy kolléga korlátlanul indíthatna, mert sosem fogyna a saját
kerete.)

**Ennek látható következménye van, és ezt nem szabad észrevétlenül elcsúsztatni.** A `userid`-t
olvassa a listaoldal **„Létrehozó"** oszlopa és a sárga „*Egy másik felhasználó által létrehozott
generálást nézel*" sáv is. Ha egy kolléga indítja el valaki más piszkozatát, mindkettő **a kollégát**
fogja mutatni, nem az eredeti létrehozót. Az oszlop felirata marad; amit közöl, mostantól az, hogy
**ki indította el utoljára a futást**.

#### 2. Mi számít „futónak"

A `generation_status::IN_PROGRESS` hármas: `generating`, `validating`, `saving`. A `started`
**nem** tartozik ide — így a tanárnak továbbra is lehet tetszőleges sok **piszkozata**, csak egy
futó munkája.

#### 3. Hol az ellenőrzés — **két úton, és a zár marad, ami volt**

**Két hely állít egy generálást `generating`-be, tehát mind a kettőnek kérdeznie kell:**

- a `generate.php` **indítási ága**, a meglévő zárolt lépésen belül, közvetlenül a rekord
  újraolvasása után, még a `draft_bank::create()` előtt — hogy egy elutasítás indítható piszkozatot
  hagyjon maga után, ne félig elindított generálást;
- a `status.php` **Újrapróbálás** gombja (Gen-015), ami egy Hiba állapotú generálást szintén
  `generating`-re állít. Ez az első körben kimaradt, vagyis a gomb megkerülte a korlátot; **András
  2026-08-06-án lezárta ezt az utat.** Az ellenőrzés a visszagörgetés **előtt** fut, mert egy
  elutasítás nem semmisítheti meg az elbukott kísérlet adatait, és ugyanazzal az üzenettel,
  ugyanoda visz, mint az indítás.

**A zár kulcsa NEM változik: marad a generálásonkénti** `generation_lock::run($generationid, …)`,
pontosan úgy, ahogy a BL-51 hagyta. Az első kör bevezetett egy tulajdonos szerinti kulcsot a
versenyhelyzet miatt; **András ezt visszavonta** (lásd a 6. pontot). Amit a generálásonkénti zár
véd, az megmarad és fontos: **ugyanannak a generálásnak a kétszeri indítása** — két piszkozat-
kategória, kétszer kifizetett futás. A négy hívási hely (forrásmentés, beállításmentés, Vissza,
indítás) tehát **mind** generálásonkénti.

#### 4. Mit lát a tanár, ha elutasítjuk

Nem elég annyi, hogy „nem lehet". A BL-53 épp azt javította, hogy egy elutasítás ne hagyjon
zsákutcát. Az üzenet **nevezze meg a másik generálást és vigyen oda** — arra a lapra, ahol a
folyamatban lévő munka állapota látszik, és ahol a **Megszakítás** gombja is van.

#### 5. AMIT EZ A FUNKCIÓ ELRONTHAT, és ezt előre le kell írni

**Egy beragadt generálás kizárja az eszközből azt, aki elindította.** Ha egy generálás `generating`
állapotban marad (elhalt cron, félbeszakadt futás), akkor a mai állapotban a tanár egyszerűen indít
egy másikat; ezzel a korláttal viszont **semmit nem tud indítani**, amíg az a sor be nem fejeződik.

Ez nem elméleti: 2026-08-06-án egy mérési célú generálás percekig állt 25%-on ezen a fejlesztői
telepítésen.

A menekülőút megvan — a státuszlap **Megszakítás** gombja —, de a tanár csak akkor talál rá, ha az
elutasító üzenet odaviszi. Ezért a 4. pont nem díszítés, hanem **ennek a funkciónak a feltétele**.

#### 6. Mit fed teszt, és mit nem — **a verseny nincs napirenden, András döntése**

Megírandó és megírt: a döntési szabály önmagában — van-e ennek a felhasználónak futó generálása —,
a három `IN_PROGRESS` státuszra és a `started`-re külön, a lezárt háromra, a felhasználónkénti
elkülönítésre és arra, hogy több futó közül a legrégebbit nevezi meg.

**A verseny nem tervezési szempont** — **András döntése, 2026-08-06.** Nem foglalkozunk azzal, hogy
két felhasználó ugyanabban a századmásodpercben nyomja meg a gombot: a korlát a **hétköznapi
esetre** szól, amikor egy tanár akkor indít másodikat, amikor az első még fut. Ezért nincs
tulajdonos szerinti zár, és ezért nincs rá teszt — egyik sem hiányosság.

#### 7. Amit ez a tétel NEM old meg

A BL-56 négy tanári problémájából egyet: **egy ember nem tömheti el a sort**. A többi három
(a kinyerés nem jelzi, hogy dolgozik; az újrapróbálkozás ugyanannyiba kerül; tíz tanár tíz
generálása továbbra is egy sorban áll) érintetlen marad.

**Amit viszont megold, és amit talán nem is ezért kértünk:** ma ugyanaz a tanár véletlenül
elindíthat kettőt ugyanarra a forrásra — kétszer kifizetve ugyanazt, és két majdnem egyforma
kérdéshalmazt kapva a jóváhagyó lapra.

#### Megvizsgálva és elvetve — nem tétel, hogy ne vezesse le újra senki

A `status.php` Megszakítás és Újrapróbálás ága a **teljes rekordot** írja vissza
(`update_record($generation)`), a lap megnyitásakor beolvasott értékekkel — a `processingtoken`
oszlopot is beleértve, amivel az ütemezett feladat lefoglalja magának a generálást. Elvileg tehát
egy közben elvégzett foglalás felülíródhat. **András 2026-08-06-án ezt elvetette**, mint
versenyhelyzeti kérdést: a versenyhelyzet nincs napirenden. A hívások változatlanok, és **nem
kerül rájuk zár**. Ez nem nyitott tétel.

#### A képernyőn ellenőrizve, 2026-08-06 — és ez a tétel egy ideig kint volt anélkül

**Ez a tétel be volt commitolva és ki volt pusholva, mielőtt bárki látta volna működni.** A tesztek
a döntési szabályt fedik, a képernyőt nem; a lezárás azért maradt nyitva, mert a különbség számít.

Két piszkozat, „BL-57 korlat A" és „BL-57 korlat B". A-t elindítottam, majd B-nél megnyomtam a
Generálás indítása gombot.

| lépés | amit a képernyő adott |
|---|---|
| B indítása, miközben A fut | **A státuszlapjára** vitt, piros üzenettel: *„Your generation »BL-57 korlat A« is still running, and one person can have only one generation running at a time. This is its status page: wait for it to finish, or stop it with the Abort button on this page, and then start the other one."* |
| a menekülőút | a **Megszakítás** gomb ugyanazon a lapon, láthatóan |
| B beállításai az elutasítás után | **megmaradtak** — 2 igaz/hamis (könnyű) + 1 feleletválasztós (közepes), összesen 3 |
| A megszakítása után B indítása | **elindult** a szokásos módon, saját státuszlapra — nincs regresszió |

A B beállításainak megmaradása az, amit az ügynök a tétel szövegén felül tett hozzá; ez a mérés
igazolja, hogy kellett. Mindkét mérési generálás a megszakítással piszkozattá vált, **AI-hívás nem
történt** — a cron egyiket sem vitte el.

**Kapuk:** phpcs `No errors`, PHPUnit **367 teszt / 2375 assertion**, verzió `2026080607`.

### BL-54 — A tanár nem látja előre a maximális fájlméretet — CLOSED 2026-08-06

**A 2026-08-05-i átadó jegyzőkönyv válasz nélkül maradt kérdése volt; András 2026-08-06-án tétellé
tette és lezárta.** A tanár sehol nem látta a korlátot — se a mezőnél, se a súgóban, se a
fájlválasztóban —, csak akkor tudta meg, amikor a feltöltés elakadt.

**Amit lát mostantól:** a fájltípusok alatt egy sor, *„Legnagyobb fájlméret: 2 MB"*, mielőtt bármit
kiválasztana.

**A kiírt érték a TÉNYLEGES korlát, nem az egyik beállítás.** Az `upload.php:130` a plugin
beállítása és a `$CFG->maxbytes` közül a kisebbiket veszi, és a fájlválasztó is ezt kapja — a kiírt
szám tehát azt mondja, mi fog történni. *(Hogy a min() melyik ágon fut, kódolvasásból tudom; a
képernyőn csak az egyik ág volt látható.)*

**Miért kézzel írjuk ki:** a Moodle `filemanager` eleme magától kitesz egy ilyen sort, a
`filepicker` — amit ez az oldal használ — nem.

**ÉS AZ ELSŐ RÁNÉZÉSRE TALÁLT IS VALAMIT, ami a tétel értelmét igazolja.** A fejlesztői telepítésen
a sor először **9,8 KB**-ot mutatott, mert a `maxfilesize` beállítás értéke ott **10 000 bájt** volt
— ezt a beállítás mezőjéből olvastam vissza, nem a képernyő számából következtettem. Ezen a
telepítésen tehát egyetlen valódi tananyag sem lett volna feltölthető, és ez addig sehol nem
látszott. **András utasítására át lett állítva a csomagolt alapértelmezésre** (`2097152`), a
Beállítások lapon, „Changes saved" visszajelzéssel.

**Ez a beállítás ugyanabban a körben a képernyős bizonyítékot is megadta:** a feltöltő oldal sora
`9,8 KB`-ról **`2.0 MB`**-ra váltott. A kiírt szám tehát nem egy beégetett érték, hanem a beállítást
követi — és mivel a 2 MiB végig is ment, a `$CFG->maxbytes` ezen a telepítésen legalább ennyi.

**Automatizált teszt nincs, szándékosan.** Egy nyelvi kulcs és egy `display_size()` hívás
megjelenése olyasmi, amit egy ránézés eldönt; egy markuphoz tapadó állítás egy még változó felületen
karbantartási teher lenne, nem védelem.

### BL-10 — Copyright fájl, és a saját szerzői jogi sor kivétele minden fájlból — CLOSED 2026-08-06

**A tétel iránya megfordult aznap, amikor lezárult, és a fordulat Andrásé.** A korábbi terv a
`@copyright` sort a cég nevére cserélte volna, a `@license` sort pedig egy nem-GPL szövegre. A
tényleges döntés: **a ránk vonatkozó szerzői jogi beírás kerüljön ki a fájlokból**, a Moodle saját
GPL-fejléce és `@license` sora **maradjon érintetlenül** — az a Moodle licence, nem ezé a beépülőé.
A beépülő jogi helyzetét egyetlen fájl mondja ki.

**Egy emlékezetből származó állítás, amit meg kellett cáfolni.** A tétel úgy élt, hogy a cég neve
már benne van a fájlokban. Nem volt benne egyetlenegyben sem: a 2026-08-04-i döntés a *szándékot*
rögzítette, a cserét magához ehhez a tételhez kötve.

**A számok, ma mérve — a tétel korábbi 141/108-as adata elavult volt:**

| | |
|---|---:|
| PHP fájl összesen (`node_modules` nélkül) | 168 |
| ebből `@copyright  2026 Koharek András` / `Koharek Andras` | **158 / 5** |
| ezekből az integritás-manifesten belül (`tools/`, `docs/`, `tests/` kizárva) | **119** |
| JS forrásfájl ugyanezzel a sorral | 6 |

**Amit a phpcs kapu ebből tiltott, és ez a tétel érdemi akadálya volt.** A `moodle-cs` forrásában
ellenőrizve, nem feltételezve:

- `moodle.Files.BoilerplateComment` a 15 soros GPL-fejblokkot szó szerint megköveteli — **ez marad,
  nem nyúltunk hozzá**;
- `moodle.Commenting.FileExpectedTags` a `@copyright` és a `@license` meglétét is megköveteli, sőt a
  `@license` **értékét a GPL-szöveghez illeszti** (`preferredLicenseRegex`).

Az eredeti terv — a `@license` átírása a saját szövegünkre — tehát **minden fájlon pirosra vitte
volna a kaput**, és nem a kód miatt. A megvalósult irányban egyetlen hibakód kizárása elég:
`FileExpectedTags.CopyrightTagMissing`. **Amit ez elveszít:** a kapu nem szól, ha egy új fájlból
lemarad a `@copyright` — ami mostantól a kívánatos állapot, tehát a szabálynak nem maradt mit védenie.

**A copyright fájl az integritás-ellenőrzés része, ahogy András kérte** — és ez nem volt ingyen. A
`license_file_integrity.php` és a `tools/generate_license.php` addig **kizárólag `.php`
kiterjesztésű** fájlokat sorolt fel, tehát épp az a fájl maradt volna fedezetlen, aminek a
tartalma a jogi állítás. Mindkét felsoroló kapott egy megnevezett kivétellistát
(`MANIFEST_EXTRA_FILES` / `ARTQTML_MANIFEST_EXTRA_FILES`).

**A két lista duplikáció, mert kénytelen: a `tools/` szkript Moodle-bootstrap nélkül, sima CLI-ként
fut, nem éri el az osztály konstansát.** Ezért teszt őrzi, hogy a kettő megegyezzen — az eltérés
következménye ugyanis távoli és drága: minden telepítés hiányzó vagy többlet fájlt jelentene minden
admin oldalon, és az ok két sor lenne két fájlban, amit senki nem néz egyszerre.

**Amit ez a változás kikényszerít, és amit a tétel korábban is előre jelzett:** minden korábban
kiadott, `files` tömböt hordozó licenc **újrakiadandó**. 119 fájl hash-e változik, plusz egy új
fájl kerül a listába.

**Kapuk:** phpcs `No errors`, PHPStan rendben, PHPUnit **356 teszt / 2328 assertion**, verzió
`2026080603`.

**A Licenc képernyő a localhoston `Valid`-ot mutat — és ez KEVESEBB, mint aminek látszik.** 119 fájl
hash-e változott ma; ha a telepített licenc `files` tömböt hordozna, a képernyőnek sérülést kellene
jeleznie. Vagyis ez a fejlesztői licenc az integritás-ellenőrzés előtti formátumú, amit a kód
szándékosan átengedőnek kezel (`verify()`, `files` kulcs nélkül). **A COPYRIGHT.txt manifesztbe
kerülését tehát nem ez a képernyő igazolja, hanem a két új PHPUnit teszt** — ezt itt írjuk le, hogy
egy későbbi olvasó ne a `Valid` jelvényre hivatkozzon bizonyítékként.

### BL-55 — Az AI-tól kapott kérdésekről minden formázás lecsupaszítása — CLOSED 2026-08-06

**András kérése és döntése, 2026-08-06.** A generátortól érkező szövegből csak a **szavak** jutnak
el a kérdésbe; a `<sub>` és `<sup>` az egyetlen kivétel, mert azok jelentést hordoznak
(H<sub>2</sub>O, m<sup>2</sup>), nem díszítést.

**Egy premisszát pontosítani kellett, mielőtt a munka elkezdődött.** A tétel úgy merült fel, hogy a
kérdések „egy az egyben" kerülnek a szerkesztőbe. Nem: a `question_form_builder::clean_ai_text()`
tizenegy helyen már addig is futtatott egy `clean_param(PARAM_CLEANHTML)`-t. Az viszont **biztonsági**
szűrő — a `<script>`-et viszi, a jóindulatú formázást szándékosan hagyja, és a Moodle nem szűkíti a
Purifier engedélyezett CSS-tulajdonságait, tehát a `background-color` átjutott rajta. A kérés jogos
volt, csak nem „nincs szűrés", hanem „a szűrés nem erre való".

**Ugyanígy pontosítandó, mi bizonyít mit.** A tanár által szerkesztett mezőbe beírt kék háttér azt
mutatja, hogy a **szerkesztő** átengedi a HTML-t — nem azt, hogy az AI útja átengedi. A kettő külön
állítás; a másodikat a kód olvasása és az új tesztek fedik.

**Két szövegvesztő csapda, amit a kézenfekvő megoldás okozott volna, és mindkettőre teszt van:**

| | |
|---|---|
| `strip_tags('x < 5 és y > 3')` | a `< 5 és y >`-t tagnak olvassa és kidobja → `x 3` marad |
| `strip_tags('<p>Első</p><p>Második</p>')` | `ElsőMásodik` — a BL-48 PDF-hibájának ikertestvére |

Ezért a sorrend: **előbb Purifier** (a magányos `<`-ből `&lt;` lesz), **utána** a blokkhatárok
sortörésre cserélése, és csak **azután** a `strip_tags`. Ez a sorrend maga a megoldás; fordítva
csendben veszít szöveget.

**Következmény, előre leírva:** a bekezdéshatárok sima sortörések lesznek, a mező viszont marad
`FORMAT_HTML`, tehát egy többbekezdéses magyarázat egy bekezdésként jelenik meg. A szavak megvannak,
a tagolás nem.

**Amit útközben ellenőriztem, és nem tartozott a kérésbe:** a plugin **saját** jóváhagyó képernyője
`s()`-sel escape-el, tehát ott a nyers HTML eddig sem renderelődött. A formázás a Moodle
kérdésbankjában és a szerkesztőben jött elő — pontosan ott, ahol András látta.

**Kilenc teszt** (`tests/local/question/ai_text_cleaning_test.php`), köztük az, hogy a `<script>`
továbbra is teljesen eltűnik: ezt a régi kód is tudta, és nem szabad elveszíteni.

**A KÉPERNYŐN IS ELLENŐRIZVE, 2026-08-06, valódi generált kérdésen — szándékosan előállított
formázással.** A generáló prompt admin által szerkeszthető, ezért ideiglenesen bekerült bele, hogy a
kérdésszöveget tegye kék hátterű `<span>`-be, egy szót `<b>`-be, és tartalmazzon `H<sub>2</sub>O`-t.
Egy igaz/hamis generálás futott le vele.

**Két, egymástól független bizonyíték egy körből:**

1. **A modell tényleg formázott** — nem feltevés: a validátor magától kifogásolta, hogy *„a kérdés
   felesleges és zavaró vizuális formázást (kék háttérszínt)"* tartalmaz. A validátor a nyers,
   mentés előtti adatot látja.
2. **A kérdésbankba mégis tiszta szöveg került.** A natív Moodle-előnézetben a kérdés HTML-je:

   ```
   Az almafa beporzását elsősorban a szél végzi, nem a méhek. A víz kémiai képlete H<sub>2</sub>O.
   ```

   Se `style`, se `<b>` — a `<sub>` viszont **megmaradt**. Pontosan a döntés szerint.

A prompt vissza lett állítva az eredetire és visszaolvasva (381 karakter, a teszt-utasítás nélkül).

**Kapuk:** phpcs `No errors`, PHPStan rendben, PHPUnit **356 teszt / 2328 assertion**, verzió
`2026080603`.

### BL-53 — Az elutasított fájl kitörli a tanár beírt szövegét — CLOSED 2026-08-06

**Egy sor törlése volt, és mindkét irányban a képernyőn ellenőrizve, nem kódolvasásból.**

A `confirm` utáni `setText('')` kikerült az `amd/src/uploadconflict.js`-ből. Helyette nem került
semmi: a sikeres ág úgyis felülírja a mezőt a kinyert szöveggel, tehát **a jó eset végállapota
változatlan** — csak a hibaág más. A mező mostantól addig tartja a tanár szövegét, amíg nincs mi a
helyére lépjen.

**A hibás esetet szándékosan állítottam elő**, ugyanazzal a módszerrel, amivel a BL-50-et néztük meg:
a `MAX_SOURCE_FILE_BYTES` ideiglenesen 100 bájt, feltöltés egy 197 bájtos `.txt`-vel, beírt szöveg
mellé.

| | a képernyőn, 2026-08-06 |
|---|---|
| a kérdés | *„The text box content will be lost and replaced with the file's extracted text. Continue?"* |
| a fájl sorsa | elutasítva — *„The document is too large or too complex to process safely…"* |
| **a beírt szöveg** | **karakterre pontosan megmaradt** (eddig üresen maradt volna) |
| a jó eset ugyanabban a körben | hibaüzenet nélkül, a fájl szövege ékezethelyesen felülírta a beírtat |

A korlát visszaállítva és visszaolvasva (`67108864`); ideiglenes nyom nem maradt a kódban.

**Új automatizált teszt nincs, és ez indoklással jár.** Ez egy JS-ág viselkedése egy `confirm` után,
és a repóból a böngészős tesztkészlet a BL-43-mal kikerült — nem maradt olyan futtató, ami a böngészőben
végigvinné. Amit PHPUnitban meg lehetne írni, az a webszolgáltatás elutasítását fedné, ami eddig is
fedve volt, és a **régi** kóddal is átment volna. A kapuk száma ezért nem is nőtt.

**A build ehhez újraépült** (`grunt amd`), és menet közben kiderült, hogy az `amd/build` tegnap óta a
forrásnál régebbi volt — egyetlen kommentsorban tért el, viselkedésben azonos volt. Nem lappangott
benne hiba.

**Egy észrevétel, ami ezen a tételen kívül esik, és nem lett hozzányúlva:** a sikeres feltöltésnél is
kiírja a lap, hogy *„The uploaded file will be ignored; only your typed text will be used"* — pedig
épp a fájl szövege került a mezőbe. A `dropFile()` mindkét ágon lefut; ez a javítás előtt is így volt.

**Kapuk:** phpcs `No errors`, PHPStan rendben, PHPUnit **345 teszt / 2307 assertion**, verzió
`2026080601`.

**Az eredeti bejegyzés következik.**

### BL-53 (eredeti bejegyzés) — Az elutasított fájl kitörli a tanár beírt szövegét, és nem tesz a helyére semmit

**A képernyőn látva, 2026-08-05, a BL-49 ellenőrzése közben.** A feltöltő oldalon volt egy beírt
forrásszöveg. Feltöltöttem egy fájlt, a rendszer rákérdezett — *„The text box content will be lost
and replaced with the file's extracted text. Continue?"* —, és OK után a fájlt **visszautasította**
(erőforrás-korlát). Az eredmény: a szövegdoboz **üresen** maradt.

**A kérdés azt ígérte, hogy a szöveget lecseréli, nem azt, hogy eldobja.** A csere nem történt meg,
mert kinyert szöveg nem lett — a törlés viszont igen. Aki egy hosszabb anyagot beírt vagy beillesztett,
azt ezzel elveszíti, és a hibaüzenet ráadásul épp azt tanácsolja, hogy *illessze be a szöveget a
forrásszöveg mezőbe*.

**Hol van a kódban.** Az `amd/src/uploadconflict.js` `loadExtractedTextThenDropFile()` ága a
megerősítés után azonnal `setText('')`-tel üríti a mezőt, és csak utána hívja a kinyerő
webszolgáltatást; ha az hibát ad, csak `alert`-et mutat, és a mező üresen marad. **Ezt a kódban
ellenőriztem, nem emlékezetből.**

**A javítás iránya:** a mező ürítése a webszolgáltatás **sikeres** válaszáig várjon, vagy hiba esetén
álljon vissza a korábbi tartalom. Az `amd/build` újraépítése is jár vele (grunt).

**Nem sürgős, de nem is elméleti:** pontosan az a helyzet hozza elő, amit a hibaüzenet javasol
megoldásként.

### BL-52 — Kisebb biztonsági megállapítások ugyanabból az újraellenőrzésből — CLOSED 2026-08-05

**A két kódban visszaellenőrzött megállapítás elkészült; a harmadik csoport mérlegelendő maradt, és
a két termékdöntés visszavonását továbbra sem fogadjuk el.**

**L-2 — a biztonsági záradék a prompt legvégén marad.** Az `ai_request::harden_system_prompt()` a nem
szerkeszthető záradékot a rendszerprompt végére teszi; az „érvénytelen JSON — újrakérés" szöveg
viszont **admin által szerkeszthető**, és a két feladat a **kész** kéréshez fűzte hozzá — tehát a
szerkeszthető szöveg a záradék *után* állt, ő lett az utolsó szó. Mostantól a szöveg a kérés
összeállítása előtt kerül a rendszerpromptba, tehát a hardenelés fut utoljára. Ára: a kérés
próbálkozásonként újraépül, ami egy tömb összeállítása.

**L-5 — a megszakítás nem törli a naplósort.** A `status.php` visszavonása kitörölte a
`token_limit_warning` sorokat, szemben a Glob-040-nel. **A törlés viszont nem indok nélkül volt ott:**
a figyelmeztetést a képernyő visszaolvassa, tehát egy megmaradt sor a következő futásnál egy már nem
létező kísérletről szólna. Ezért a sor marad és megjelölést kap az adatblokkjában — a jelentés
szóhasználatával: redaktálva, nem eltávolítva. Minden technikai mező érintetlen. **A jelölés egy
kísérletre szól, nem a generálásra**, különben egy megszakítás elnémítaná az összes későbbi
figyelmeztetést is — ami ugyanannak a hibának a halkabb változata.

**EGY HIBÁT A SAJÁT TESZTJE FOGOTT MEG, és ez a rész marad meg belőle.** A figyelmeztetést előállító
függvény végén generikus üzenet áll arra az esetre, ha egy naplósorból nem olvasható ki a szakasz. A
jelölés bevezetése után a függvény minden sort átlépett — és **mégis** a generikus figyelmeztetést
adta vissza, mert a korai kilépés csak azt nézte, van-e egyáltalán naplósor. Két teszt bukott el
belőle. A függvény mostantól számolja, hány sort vett figyelembe, és ha egyet sem, hallgat.

**A képernyőn ellenőrizve, 2026-08-05.** A megszakítás végigfut hiba nélkül és a piszkozat lapra tesz
vissza (a törlés helyén futó új kód lefut), és **egy valódi generálás végigment** az átírt
kérésépítéssel: *„Generation completed successfully — 1 question(s) generated."* Ez utóbbi az első
próbálkozás útját bizonyítja; **a második próbálkozás promptsorrendjét a képernyőn nem láttuk**, mert
ahhoz a modellnek értelmezhetetlen választ kellene adnia — azt a rész a kódolvasás és a kapuk
fedezik.

**Kapuk:** phpcs üres, PHPStan `No errors`, PHPUnit **336 teszt / 2280 assertion**, verzió
`2026080506`.

**AMI NYITVA MARAD ebből a tételből, mérlegelendőként, nem feladatként** — a kódban visszaellenőrizve
2026-08-05-én, nem a jelentésből átvéve:

- **L-1 ELVETVE, mérésből.** Megírtam, és a repó **saját tesztje** buktatta el:
  `test_source_text_cannot_escape_its_json_field()` azt rögzíti, hogy egy „ignore previous
  instructions" típusú mondat a tanár dokumentumában **változatlanul** jut át a JSON-mezőbe. Ez nem
  hiányosság, hanem a felépítés: a forrásszöveg nem szűréstől biztonságos, hanem attól, hogy
  strukturált, megbízhatatlanként jelölt mezőben utazik a nem szerkeszthető biztonsági záradék
  mellett. A providerhívás előtti elutasítás ezt vonná vissza, és **valódi tananyagot utasítana el**
  — egy prompt-injectionről szóló oktatási anyag pontosan ilyen mondatokat tartalmaz —, ráadásul egy
  ütemezett feladatban nincs kinek kérdést feltenni. A beviteli határon lévő szűrés marad, ahol van:
  ott a tanár lát üzenetet és tud dönteni. Az indoklás a `build_user_content()` fölött is ott áll,
  hogy ne próbáljuk újra.
- **L-3 TÁRGYTALAN.** Az üres lapút utáni teljes fallback költségéről szólt — és aznap az a fallback
  megszűnt: a bejárható, de szöveget nem adó PDF ma **elutasítás** (BL-49, András döntése). A
  költség, amit mérlegelni kellett volna, nem létezik.
- **L-6 KÉSZ.** `tests/privacy/provider_test.php`, hat teszt. A közös állítás: **a naplósor túléli a
  törlést** (Glob-040), és csak az azonosító része tűnik el — a `userid` és a `generationid` nullra
  megy, az eredeti azonosító átkerül az `originalgenerationid`-be, a technikai mezők érintetlenek.
  Külön teszt arra, hogy egy másik tanár generálása nem tűnik el; hogy aki csak szerkesztett vagy
  jóváhagyott, annak a neve lekerül, de a kérdés marad; hogy a felhasználólista mindkettőjüket
  megnevezi; és a teljes kontextus törlésére meg a jóváhagyott felhasználólistára külön, mert azok
  külön metódusok, külön eséllyel visszahozni a törlést.
- **L-7 KÉSZ.** Három további teszt: a 4096-os archívum-bejegyzésszám, a 100-szoros tömörítési arány,
  és — ez a lényeg — **egy hétköznapi, negyven képet tartalmazó dokumentum, ami egyik korlátba sem
  ütközik**. Ez utóbbi nélkül a másik kettő nem bizonyít semmit: ez a projekt már beállított egyszer
  olyan korlátot (256 bejegyzés, 32 MiB), ami valódi tananyagot utasított volna vissza. **És változatlanul áll, hogy a
javítási terv 7. és 11. fejezete nem biztonsági megállapítás, hanem korábbi termékdöntések
visszavonása** — a kötelező megerősítő jelölőnégyzet és a megjelenés-alapú számlálók visszahozása.
Ezek Andráséi, és nem változtak.

**Az eredeti bejegyzés következik.**

### BL-52 (eredeti bejegyzés) — Kisebb biztonsági megállapítások ugyanabból az újraellenőrzésből

**Kettő közülük a kódban visszaellenőrizve, és mindkettő kicsi:**

- **A biztonsági záradék sorrendje (L-2).** JSON-újrapróbálkozásnál a
  `generate_questions_task.php` és a `validate_questions_task.php` az admin által szerkeszthető
  `promptjsoninvalid` szöveget a **már hardenelt** system prompthoz fűzi hozzá, tehát a nem
  szerkeszthető záradék *után* megjelenik egy szerkeszthető system szöveg. A záradék kerüljön a
  prompt legvégére, vagy az újrapróbálkozási utasítás legyen kódban rögzítve.
- **Naplósor törlése megszakításkor (L-5).** A `status.php:58` a rollbackben **törli** a
  `token_limit_warning` naplósorokat, ami szemben áll a Glob-040-nel: a naplósor túléli a
  generálást. A sor maradjon meg, szükség esetén redaktálva.

**A többi kis megállapítás mérlegelendő, nem automatikus:** prompt-injection újraszűrés közvetlenül
a providerhívás előtt (L-1, defense-in-depth legacy rekordokra), az üres lapút utáni teljes fallback
költsége (L-3), valamint a privacy provider és az erőforráskorlát tesztlefedettsége (L-6, L-7).

**AMIT A JELENTÉS HIBÁNAK NEVEZ, DE DÖNTÉS VOLT.** A hozzá tartozó javítási terv
(`08_nem_teljesen_megoldott_biztonsagi_javaslatok_kodszintu_javitasa.md`) két fejezete a termék
korábbi döntéseit vonná vissza, és ezeket nem lehet biztonsági megállapításként elintézni:

- a **7. fejezet** kötelező megerősítő jelölőnégyzetet kér a „nagy fájl, kevés szöveg" esetre —
  András ezt szándékosan figyelmeztetésnek hagyta, nem elutasításnak, mert a sok képet és kevés
  szöveget tartalmazó dokumentum jogos eset;
- a **11. fejezet** visszahozná a `smallfontruns` / `invisibleruns` / `vanishedruns` számlálókat,
  vagyis pontosan azt a gépezetet, amit 2026-08-04 délután András elvi döntése alapján kivettünk.
  A terv korrekt abban, hogy ezek nem dobhatnának el szöveget — de visszahozzák a mérési és
  karbantartási terhet egy olyan figyelmeztetésért, amit nem tudunk kalibrálni.

**A terv mérete önmagában is szempont:** 1743 sor, tizenkét munkacsomag, új osztályokkal, MUC-alapú
rate limittel, lockkal négy belépési pont körül és ad-hoc taskkal. A haszon java a BL-50 egysoros
méretellenőrzésében, a BL-49 generátorában, a BL-51 részleges update-jében és a fenti két apróságban
van.


### BL-51 — A generálás állapotváltása és a mentés között versenyhelyzet van — CLOSED 2026-08-05

**Két lépésben zárult, ahogy a tétel eredetileg is javasolta, és mindkettő a képernyőn ellenőrizve.**

**1. Részleges frissítés.** Mindhárom mentés csak a saját oszlopait írja ki, névvel felsorolva — és
**három** hely volt, nem egy: a jelentés csak a forrásmentést nevezte meg.

| hely | mit írt felül |
|---|---|
| `generation_source_service::save()` | a `status`-t — az elindított generálás visszaállhatott `started`-re |
| `generate.php` beállításmentés / Vissza | a `status`-t **és a forrásszöveget** is, a lap megnyitásakori állapotra |
| `generate.php` indítás | a **forrásszöveget**, tehát egy közben elvégzett szerkesztést |

**2. Generálásonkénti Moodle zár** (`local\generation_lock`), mert a részleges frissítés a képernyők
egymásra hatását szüntette meg, a sajátjukra nem. Mind a négy hely — forrásmentés, beállításmentés,
Vissza, indítás — a beolvasás–döntés–írást egyben futtatja. **Az indításnál a zár a
piszkozat-kategória létrehozását is körbeveszi**, mert az a rész, aminek nem szabad kétszer
megtörténnie. A második kérés ezt kapja: *„Valaki más éppen ezen a generáláson dolgozik. Várj egy
pillanatot, és próbáld újra."*

**EGY MÉRÉS, AMI FELTEVÉST DÖNTÖTT MEG, és a kódba is bekerült.** Az első teszt azt akarta
bizonyítani, hogy egy már zárolt generálásra a második kérés nem kapja meg a zárat — és **elbukott**.
A `lock_config::get_lock_factory()` minden hívásra új fabrikaobjektumot ad, az „ne rakd egymásra"
védelem az adott objektum saját listája, a MySQL/MariaDB `GET_LOCK` pedig **ugyanazon a
kapcsolaton belül újra megadható**. A védelem tehát kérések között áll fenn, nem egy kérésen belül.
Következmény, ami a `generation_lock` fejlécében is ott van: **ezeket a zárakat sosem szabad
egymásba ágyazni.** A négy hívási hely ma nem ágyazódik.

**Új teszt a versenyre nincs, és ez indoklással jár.** A versenyt egy PHPUnit-folyamatból nem lehet
előidézni. Ami megírható volna — „mentés után a státusz változatlan" — a **régi** kóddal is átment
volna. Egy teszt, ami nem tud elbukni, nem védelem. Ami készült: a zár szerződése (lefut, felszabadul,
**kivétel esetén is** felszabadul, generálásonkénti), és a fenti feltevés rögzítése.

**A képernyőn ellenőrizve, 2026-08-05, mind a négy út:** a beállítások mentése (2/3 megmaradt), a
forrásszöveg mentése (124 → 61 karakter, visszatöltve is az új), és **a teljes indítás élesben** —
piszkozat-kategória, státuszváltás, sorbaállítás, majd a háttérfeladat lefutott:
*„Generation completed successfully — 1 question(s) generated."*

**Kapuk:** phpcs üres, PHPStan `No errors`, PHPUnit **334 teszt / 2272 assertion**, verzió
`2026080504`.

**EGY MULASZTÁS, AMI EBBŐL A KÖRBŐL DERÜLT KI.** A forrásmentés a localhoston először
`Class "local_artqtml\local\generation_lock" not found` hibára futott: **új osztály után a futó
telepítésen le kell futtatni az upgrade-et és a cache-ürítést**, különben az autoloader nem tud róla.
A PHPUnit ezt elfedi, mert a `gates.sh` a verzióemelésre újrainicializálja a tesztkörnyezetet. Ugyanez
visszamenőleg a BL-49-et is érintette: a `pdf\resource_limit_exception` ág aznap délelőtt ugyanígy
elszállt volna, csak nem próbáltuk ki. Az upgrade után kipróbálva jó.

**Az eredeti bejegyzés következik.**


**Ugyanabból az újraellenőrzésből (M-3), a kódban visszaellenőrizve — és a jelentésnél egy fokkal
élesebb.**

`generation_source_service::save()` sima `get_record`-ot csinál, ellenőrzi a státuszt, majd a
**teljes rekordot** írja vissza `update_record`-dal. A delegált tranzakció önmagában nem zárol sort,
tehát a két lépés között egy másik kérés elindíthatja a generálást. Mivel a visszaírás minden
oszlopot magával visz, nem csak a forrásszöveg íródhat felül: **a `status` is visszaállhat
`generating`-ről `started`-re.** A `generate.php` beállításmentési és indítási ága ugyanígy korábban
betöltött rekorddal dolgozik.

**Két külön lépés, és az elsőnek nincs szüksége a másodikra.** A **részleges update** — csak a négy
saját oszlop írása — megszünteti a státusz felülírását lock nélkül. A közös, generálásonkénti Moodle
lock a forrásmentés, beállításmentés és indítás körül ezután jön, a check-then-act miatt.


### BL-49 — A PDF oldalankénti olvasása egy oldal összes adatfolyamát memóriában tartja — CLOSED 2026-08-05

**Lezárva a képernyőn ellenőrizve, nem kódolvasásból.** Három dolog változott, és a harmadik András
döntése volt, nem a jelentésé.

1. **A `page_content_streams()` generátor.** Egy oldal adatfolyamai eddig együtt vártak a memóriában
   egy tömbben, és az összesített ellenőrzés csak a kész tömb után futott. Mostantól egyszerre egy
   adatfolyam él, legfeljebb 16 MiB, és a számláló azzal az egy adatfolyammal a kézben ellenőriz.
2. **`MAX_PAGE_CONTENT_STREAMS = 64`, deduplikálás helyett.** A jelentés a kettő közül bármelyiket
   elfogadta volna. A deduplikálás azért esett ki, mert a `/Contents` tömb **összefűzés**: a kétszer
   felsorolt objektum egy érvényes dokumentumban jogosan adja hozzá kétszer a szövegét. A 64 becslés,
   nem mérés — nem volt olyan fájl, amin megmérhető lett volna, és a kód ezt ki is mondja.
3. **A korlát átlépése eddig a DRÁGÁBB utat indította el.** Az `object_index::build()` ugyanazt a
   `null`-t adta a „nem olvasható" és a „korlátba ütköztem" esetre, a hívó pedig az elsőre a régi,
   teljes fájlos olvasással válaszol. A kettő szétvált: a korlát `pdf\resource_limit_exception`-t
   dob, amiből `rejected/resource_limit` lesz. Négy korlátot érint: objektumszám, oldalszám,
   objektum-adatfolyamok száma, egy kicsomagolt objektum-adatfolyam mérete.

**András döntése 2026-08-05, és ez túlmutat a BL-49-en: a hibás fájlt el kell utasítani, nem félig
feldolgozni.** Három dolog vezetett a régi, teljes fájlos olvasásra; a döntés kettőt elutasítássá
tett, egyet meghagyott:

| eset | mi lett belőle |
|---|---|
| erőforrás-korlát az objektumtérkép építésekor | **elutasítás** (`resourcelimit`) |
| a szerkezet bejárható, de egyetlen betűt sem ad | **elutasítás** (`notext`, új indokkód) |
| nincs oldalobjektum a fájlban | **marad visszalépés** — ez nem hibás fájl, így néz ki egy egyszerűbb vagy régebbi PDF |

A `notext` **nem kapott új nyelvi szöveget**: a meglévő, nem részletezett kinyerési hiba szövege
pontosan azt mondja, amit ez az eset kíván, és két egyforma tanácsot nem érdemes külön karbantartani.

**A képernyőn ellenőrizve, 2026-08-05.** A bejárható, de szöveget nem adó PDF-re a tanár azt kapja,
hogy *„The text could not be extracted from this file. Paste the text you want to use into the
source-text field."* — nem a „túl nagy vagy túl összetett" üzenetet. Az oldalobjektumok nélküli PDF
szövege ugyanabban a körben átjött a Forrásszöveg mezőbe.

**Kapuk:** phpcs üres, PHPStan `No errors`, PHPUnit **330 teszt / 2263 assertion**, verzió
`2026080501`. Négy új teszt: a korlát elutasít és nem esik vissza; a szöveget nem adó oldalszerkezet
elutasít; az oldalobjektum nélküli fájl **átmegy**; és 100 hivatkozásból 64 olvasódik.

**A dokumentáció ugyanabban a körben ment át** (`specifikacio_v42.md` 220. sor és Felt-036,
`technikai_melleklet_v19.md` 5.2 és 5.2.a), a `.docx` mindkettőből újraépült és átment az oda-vissza
körén. A specifikáció 220. sora **az ellenkezőjét mondta** — az üres eredményt „normál üres mezőként,
nem hibaként" kezelte —, és a javítás ezt meg is nevezi.

---

**Az eredeti bejegyzés, az érvelés miatt megtartva:**

### BL-49 (eredeti bejegyzés) — A PDF oldalankénti olvasása egy oldal összes adatfolyamát memóriában tartja

**Egy külső biztonsági újraellenőrzésből, 2026-08-04 este (`07_biztonsagi_ujraellenorzes_v42.md`,
M-1). A kódban visszaellenőrizve, nem átvéve.**

`text_extractor::page_content_streams()` egy oldal **összes** kicsomagolt adatfolyamát tömbbe
gyűjti, és az összesített `MAX_TOTAL_INFLATED_BYTES` ellenőrzés csak azután fut, hogy a tömb már
elkészült. Egy oldal `/Contents` bejegyzéseinek számára **nincs korlát**, és ugyanarra az objektumra
többször is lehet hivatkozni — minden hivatkozás újra kicsomagolódik. A csúcsmemória tehát nem egy
adatfolyam, hanem a hivatkozások száma szorozva az adatfolyamonkénti 16 MB-tal, vagyis nincs plafon.
A jelentés példája (húsz hivatkozás egy 15 MB-ra kibomló objektumra, ~300 MB) a kód szerint megáll.

**Ez a technikai mellékletben egy ideig fordítva állt.** A „csúcsmemória egyetlen adatfolyam" mondat
a teljes fájlos útra igaz, és az oldalankéntire ellenőrzés nélkül lett ráírva; a melléklet 2026-08-04
este javítva lett, és a javítás megnevezi a hibát.

**A javítás iránya, a jelentés szerint és egyetértve vele:** a `page_content_streams()` adjon vissza
generátort, az adatfolyam a kicsomagolás után azonnal dolgozódjon fel és essen ki, és az összesített
számláló a tömbbe helyezés **előtt** ellenőrizzen. Az `object_index` kapjon összesített tárolt-bájt
korlátot, az ismételt objektumhivatkozások pedig deduplikálást vagy külön darabszám-korlátot.

**Kapcsolódó, ugyanebből a jelentésből:** a limitátlépés ne drágább fallbacket indítson, hanem
`rejected/resource_limit` eredményt adjon; és minden PDF regex-út után legyen `preg_last_error()`
ellenőrzés.

**Súlyosság a saját telepítésre nézve.** A támadó `local/artqtml:use` jogosultságú, tehát tanár,
nem a nyílt internet. Javítandó, de nem éles seb.

### BL-50 — A DOCX út méretellenőrzés nélkül másol temp fájlba — CLOSED 2026-08-05, az egysoros fele

**A méretellenőrzés kész, és a képernyőn is ellenőrizve.** Az `extract_docx()` **első művelete** a
`copy_content_to_temp()` volt, tehát egy túlméretes dokumentum előbb lemezre került, és csak azután
nézte meg bárki a méretét; a TXT és a PDF ág ezt mindig is a fájl megnyitása előtt ellenőrizte.

**Miért számít, ha a fájlválasztó úgyis véd.** A feltöltő oldalon nem látszik: ott a Moodle
fájlválasztója már a feltöltés előtt visszautasítja a beállított méretnél nagyobb fájlt. A
`local_artqtml_extract_text` webszolgáltatás viszont közvetlenül is hívható, és ott a fájl a hívó
**saját** piszkozat-területéről érkezik, ahová bármelyik másik Moodle-felület is feltölthetett — nem
a plugin 2 MiB-os alapértelmezése, hanem a `$CFG->maxbytes` szerint. *(Ez a mondat kódútból
következtetés, nem mérés.)*

**A képernyőn, 2026-08-05.** A korlátot ideiglenesen leszállítva egy hétköznapi `.docx` feltöltése az
`errorfileresourcelimit` üzenetét adta; a konstans visszaállítva és visszaolvasva. Ugyanabban a
körben egy valódi magyar `.docx` (37 157 bájt) szövege ékezethelyesen bekerült a Forrásszöveg mezőbe,
tehát a korai kilépés hétköznapi dokumentumot nem érint. **Mindhárom útra van teszt; eddig egyikre
sem volt.**

**Ami ebből a tételből NYITVA MARAD, és külön mérlegelendő:** a `local_artqtml_extract_text`
végpontnak továbbra sincs felhasználónkénti hívásszám-korlátja, párhuzamossági korlátja, sem
contenthash-alapú rövid idejű gyorsítótára. A tétel eredeti szövege szerint is ez a **második**
lépés: az olcsó méretellenőrzés önmagában sokat visz, a rate limit és a gyorsítótár csak utána
indokolt — és a BL-49 nélkül csak takarta volna a problémát.

**A dokumentáció ugyanabban a körben ment át:** `technikai_melleklet_v19.md` 5.2.a és
`specifikacio_v42.md` Felt-036 — utóbbi sor két állítása 2026-08-05-ig nem volt igaz minden úton.

---

**Az eredeti bejegyzés, az érvelés miatt megtartva:**

### BL-50 (eredeti bejegyzés) — A DOCX út méretellenőrzés nélkül másol temp fájlba, és a kinyerő végpontnak nincs korlátja

**Ugyanabból az újraellenőrzésből (M-2), a kódban visszaellenőrizve.**

`text_extractor::extract_docx()` **első művelete** a `copy_content_to_temp()`. A TXT és a PDF ág is
ellenőrzi a `MAX_SOURCE_FILE_BYTES` értéket a fájl megnyitása előtt, a DOCX nem. **Ez egy sor.**

A `local_artqtml_extract_text` webszolgáltatásnak nincs felhasználónkénti hívásszám-korlátja,
párhuzamossági korlátja, sem contenthash-alapú rövid idejű gyorsítótára; minden hívás újrafuttatja a
teljes feldolgozást. A végpont a hívó **saját** piszkozat-területére korlátozódik, de azon belül
bármelyik draft item megadható — ezt 2026-08-04 este a böngészőből kipróbáltam, tetszőleges
azonosítóval hívva a végpontot.

**A sorrend számít:** a méretellenőrzés olcsó és önmagában sokat visz; a rate limit és a
gyorsítótár csak utána indokolt, és a BL-49 nélkül csak takarná a problémát.


### BL-48 — A PDF's text is not stored as text — CLOSED 2026-08-04, all three points done and measured on the screen

**Closed the same evening it was opened, after the fix was watched working on localhost rather than
reasoned about.** All three undecided points below were built:

1. **The plausibility guard** — `upload.php` warns on screen when a file yields very little text for
   its size, so the silent failure the item was really about is gone.
2. **`/ToUnicode` is read** — `classes/local/pdf/object_index.php` resolves the object graph and
   `classes/local/pdf/tounicode_cmap.php` parses the table.
3. **The space-joining** — pieces are joined with nothing and lines break on real vertical movement,
   which was measured as an improvement on a real file.

**AND THE FIX WAS NOT FINISHED WHEN IT WAS FIRST CALLED FINISHED, which is the part worth keeping.**
The afternoon's work was handed over as complete on the strength of green gates. Uploading one
ordinary Hungarian document to localhost that evening found four further faults, in the order they
surfaced:

- **Every PDF upload hit a fatal error.** The reader had been changed to return nothing while the
  caller still declared that it returned a number.
- **Most of the text came out as punctuation.** The glyph table was applied only to hexadecimal
  operands, and a Word export writes every page as ordinary `( )` strings with one-byte subset
  codes. `!"#"$%&'()` instead of `A körte: Történelem, egészség és kulináris élvezetek`.
- **The document's title stayed wrong after the rest was right.** One font's compressed glyph table
  lost **a single byte**, because the code trimmed the trailing newline off binary data. zlib
  refused the truncated stream and that font silently had no table at all. `/Length` is now
  authoritative.
- **The words ran together** (`A fenségeséssokoldalúgyümölcs`): the space between two words is often
  its own show-text operation, and whitespace-only pieces were being discarded as empty.

**One byte failed the whole upload.** A single non-UTF-8 byte out of the Hungarian text made Moodle
reject the entire web-service response, and the teacher was told the file contained no text — the
document had been read correctly and was thrown away at the last step.

**The measurement that closes this.** `A körte.pdf`, 47,425 bytes: 4,768 characters extracted,
against 4,674 from the same document as DOCX and 4,635 as TXT. Before the evening's four fixes the
same file produced nothing at all.

**The re-measurement this item asked for is moot.** It asked for the hidden-text rule to be
re-measured once `/ToUnicode` worked. That rule was removed on András's decision the same afternoon
— all authored text goes into the source-text box, and only structurally non-authored content is
left out — so there is no rule left to measure.

**Original entry follows.**

#### BL-48 (original entry) — A PowerPoint-exported PDF yields 64 characters out of 17,500, and nothing says so

**Opened 2026-08-04 by András, on a measurement made the same day.** The trigger was a real teaching
file: `IDD_1_0228_képekkel.pdf`, 21 slides on insurance-sales law, exported by *Microsoft® PowerPoint®
for Microsoft 365*, 1,124,215 bytes.

**What the extractor gets out of it:**

```
K ar ak t er ek - - M ent or  Z solt - Anna - - fia t al  pasa s
```

**64 characters. The file's own text layer is roughly 17,500** (poppler `pdftotext`, the same 21
pages). Everything the teacher uploaded the file for — the legal chapter, the dialogue scenes, the
explanatory paragraphs — is absent.

**Measured twice, on both versions of the extractor.** The 263-line version that stood this morning
returned 68 characters; the 892-line rewrite of 11:53 (resource limits and hidden-text detection)
returns **64**. The rewrite did not touch this problem, and was not meant to.

| | |
|---|---:|
| pages | 21 |
| characters in the PDF's text layer | **~17,500** |
| characters `text_extractor::extract_pdf()` returns | **64** |
| embedded fonts | 6 |
| of those, CID TrueType with `Identity-H` encoding | **5** |
| hex-encoded strings in the content streams | 6,781 |
| images in the file | 40+ |

**The cause, and it is not "a broken PDF".** `extract_pdf()` collects `( ... ) Tj` and `[ ... ] TJ`
operands — text written out as literal characters. Five of the six fonts in this file are CID
TrueType with `Identity-H`, where the text sits in the stream as **hexadecimal glyph identifiers**,
not letters. Turning those back into text needs the font's own `/ToUnicode` mapping table, which the
code never looks at. The 64 characters come from the single conventionally-encoded font in the file
(Aptos, WinAnsi). This is the normal output of Microsoft Office, not an edge case.

**Two smaller faults visible in the same 64 characters.** The fragments are joined with a space
(`implode(' ', $text)`), so even successfully read text breaks mid-word — `K ar ak t er ek`. And the
file's 40+ images contribute nothing, by design: there is no OCR, which the class docblock already
states.

**The part that matters most: the failure is silent, and the 11:53 rewrite did not change that.**
The result is `STATUS_OK`, not a rejection: `upload.php` line 293 only turns away `STATUS_REJECTED`,
so the 64 characters go through, line 307's empty check passes, and there is **no minimum-length
check anywhere** — the two length rules on that page are the 100-character limit on the *name*
(line 263) and `source_text_limit::is_exceeded()` (line 320), which guards the upper end. The
teacher sees a successful upload and finds out something is wrong only when the generated questions
turn out to be about nothing.

**A file-level specification exists**, written the same day and measured on the same file:
`BL-48_pdf_szovegkinyeres_spec_2026-08-04.md` in the iCloud work documents. It names every file to
add or change, the four resource limits, the test fixtures, and — measured in a Python prototype —
that resolving `/ToUnicode` recovers **16,033 of the ~17,500 characters**. Two findings in it are
not in the summary below: the page-driven route inflates **0.16 MiB instead of 23.47 MiB**, and the
hidden-text rule added on 2026-08-04 has to be re-measured afterwards, because it currently sees
none of the 6,781 hex strings and would then see all of them.

**Not decided — three separable pieces, smallest first:**

1. **A plausibility guard on the extracted text.** Refuse, or warn on screen, when a file of this size
   yields a handful of characters. Cheap, removes the silent failure, and is worth doing whether or
   not 2 happens.
2. **Read `/ToUnicode`.** The correct fix for Identity-H, and it stays dependency-free in the sense the
   docblock requires: a `/ToUnicode` CMap is itself a FlateDecode stream of plain text, so the zlib
   the class already uses is enough. Size unknown, not estimated.
3. **The space-joining.** Only worth touching once 2 makes the output long enough to read.

**How this was measured, and its limits.** There is no PHP in the assistant's environment, so
`extract_pdf()` and `extract_text_operators()` were **re-implemented character-for-character in Python**
— same regular expressions, same zlib decompression — and run against the file. The ~17,500 comparison
figure and the font/image inventory come from poppler (`pdftotext`, `pdffonts`, `pdfimages`). **The
upload screen itself was not opened**; the silent-acceptance claim above is read from `upload.php`, not
observed in the browser. Both should be confirmed on localhost before this item is worked.

### BL-43 — A kézi bejárási lista, a böngészős tesztkészlet helyett — CLOSED 2026-08-04

**András döntése, 2026-08-02: a vizuális alapképek és a böngészős tesztkészlet nem halasztva, hanem
elejtve lettek.** Az érv a projekt saját mérése volt: azon a napon, amikor az alapképeket kivettük,
12 bukás közül **0 volt valódi regresszió**; a 748 esetes regiszterből a CI **29-et** futtatott, 4%
alatt; és aznap minden valódi megállapítást ember talált, nem futtató.

**Amit helyette kaptunk:** a `manualis_bejaras_v2.md` — **252 bejárási pont, 12 fejezet**, a
specifikáció **334** élő követelményéből **334** lefedve, 0 fedetlen. Egy teljes kör becsült ideje
kb. **6 óra** (becslés, nem mérés).

**Amit tudottan elveszítettünk, és tudva vállaltuk:** aki tesztel, azt nézi meg, amihez hozzányúlt.
Egy közös változás — egy nyelvi kulcs, egy stíluslap, egy `version.php` — el tud rontani olyan
képernyőt, amit utána senki nem nyit meg. Ezt a fajta regressziót mostantól ember fogja meg, vagy
senki. Ide tartozott a képernyőszélesség-ellenőrzés is (Glob-034/035), amit a bejárás pontként visz
tovább.

**Ami a takarításból megmaradt:** a `tools/script-guard.sh` egyetlen állítása — minden `tools/**.sh`
futtatható marad. Ez a rewrite első percében igazolta magát: a `sed -i` levette a futtathatóságot a
`tools/check.sh`-ról, az őr elbukott, és visszaállítottuk.

**A maradék könyvtárak 2026-08-06-án kerültek ki**, a hivatkozásokkal együtt.

### BL-45 — `docs/` left the repository — CLOSED 2026-08-04, moved to iCloud

**Decided and done by András, 2026-08-04: the whole folder goes.** Not the lighter alternative
below (untracking only the `.docx`) - all of it, to
`~/Library/Mobile Documents/com~apple~CloudDocs/moodle_dev_projektek/artqtml-munkadokumentumok/termekdokumentumok/`,
next to the working documents that moved out on 2026-07-30. 30 files, 17 MB, verified file by file
against the source before the repository copy was removed.

**What had to move with it, and this was the whole difficulty of the item.** One thing in the
repository actually read `docs/` on every push: **assertion 2 of `selection-guard.sh`**, the
register-pin freshness check, which also runs as a CI step. CI cannot see iCloud, so left in place
it would have failed on every push - not because the pin was stale, but because the file was gone.
It is removed, with the reasoning written into the script at the point where it used to be.

**What that costs, recorded rather than argued away:** this is the same guard that caught the stale
v43 pin on 2026-08-02. If somebody builds a new register, nothing now says that `register.ts` still
points at the old one.

**Why it is acceptable today, checked in the source rather than assumed:** `REGISTER_VERSION` is
read by exactly three files of the browser suite, out of CI since 2026-07-30 and
dropped with BL-23. The guard was protecting the freshness of a number nothing reads.

**When it comes back:** if BL-43 keeps the register in use, or if the browser suite is ever switched
back on. Assertions 1, 3 and 4 are untouched and still run; the guard was verified green after the
change (3 assertions, exit code 0).

**Also removed in the same round, on András's decision:** `tools/reports/`. The Configurable
Reports block is not part of the installation (BL-08), the reports live in the Moodle site where
they are, so the five `.sql` files in the plugin directory had nothing to be kept in step with. The
open item that asked to compare them against their live counterparts is not deferred - it is moot.

**Left behind on purpose, named so it is not mistaken for an oversight:**
the suite's spec-selection script still classified `docs/tesztesetek_v*` as `REGISTER` and
`docs/*` as `DOCS` (lines 70-71). Those branches now match a path that does not exist, which is
harmless - and BL-43 rewrites this file's role anyway, from a test selector into "which screens
must I walk after this change". Changing it now would be churn against a file about to be rewritten.

---

**The original item, kept for the reasoning:**

### BL-45 (original entry) — Does `docs/` belong inside the plugin repository?

**Raised by András, 2026-08-03, after a personal working copy reached the history.** A `git add -A`
swept three untracked files in `docs/` into a commit: two Word owner files (162 bytes each, junk)
and **`MoodleAI_kerdesgenerator_kezikonyv_v6_KA.docx`, 4.87 MB** - András's own edited copy of the
manual, which has no business in the repository. The push carried 4.46 MB. The owner files are now
covered by a `.gitignore` rule (`**/~$*`, `.~lock.*#`); the 4.87 MB is in the history and can only
be removed by rewriting it.

**His proposal:** take `docs/` out of the plugin altogether and keep it where the working documents
already live (`~/Library/Mobile Documents/.../moodle_dev_projektek/artqtml-munkadokumentumok/`), the same move the
session records, prompts and reports made on 2026-07-30.

**What `docs/` actually holds, measured 2026-08-03:**

| | | |
|---|---:|---|
| `.docx` | **13 MB** | 7 files - **generated** from the `.md` sources by `tools/build_docs.py`, with an enforced round-trip check |
| images | 4.9 MB | 14 PNGs, the manual's Hungarian figures |
| `.md` | 768 KB | 9 files - the specification, the technical annex, the manual, the register, the field table, the open questions, the manual walkthrough |
| `.xlsx` | 88 KB | the test register |
| **total** | **18 MB** | |

**What depends on `docs/`, so nobody re-derives it when this is picked up:**

- **The register pin guard reads it.** The suite's selection guard resolved the highest
  register from `docs/tesztesetek_v*.xlsx`, and so do `tests/register-pin.spec.ts:73` and
  the suite's Excel reporter. Move the folder and the guard has nothing to compare
  against - the exact guard that caught the stale v43 pin on 2026-08-02.
- **`select-specs.sh` classifies it**, and deliberately splits it in two: `docs/tesztesetek_v*` is
  `REGISTER` ("not documentation"), everything else under `docs/` is `DOCS` (lines 70-71).
- **`tools/build_register.py`** writes the register there; **`tools/build_docs.py`** builds the docx
  from the md there.
- **Neither the shipped package nor the licence manifest is affected** - both already exclude
  `docs/` (`CLAUDE.md`'s package command; `license_file_integrity.php:121`).

**The lighter alternative, worth weighing before the folder is moved:** the `.docx` files are
**build outputs**, regenerated from the `.md` with a verified round trip. That is 13 of the 18 MB.
Untracking just those removes most of the weight and the whole class of accident that started this
item (András's copy is a `.docx`), while leaving the specification under version control - where
the project's standing discipline puts it, because "the specification follows the code" only means
something if the two move in the same history.

**The argument on the other side, and it is his:** none of these documents is read by the plugin,
shipped in the package or used by CI - which is the same reasoning that moved the working documents
out on 2026-07-30. The counter-argument is that the register *is* used by a guard, and the
specification is what a later reader compares a release against.

**Not decided. Not urgent.** Nothing is broken today; the leak that started it is closed.

### BL-08 — Ship a Configurable Report with the installation — CLOSED 2026-08-03, decided against

**Decided by András, 2026-08-03: the report is not part of the installation, and no dependency is
declared.** The item asked for the opposite, so it closes as answered rather than as done.

**What forced the decision.** Adding `block_configurable_reports` to `$plugin->dependencies` would
make Moodle refuse to install *or upgrade* on any site without that block - and a missing dependency
halts the whole site's upgrade, not just this plugin's. That bar is met by `qtype_ordering`, where
the plugin's own function breaks without it (M-05). It is not met here: without the block the log
is written exactly the same, it is only read from SQL instead.

**And the specification had already decided it, in the opposite direction to the request.**
`specifikacio_v42.md:757` and `Admin-063` both state in as many words that the plugin takes no
dependency on `block_configurable_reports`; `classes/local/model_check_log.php:25` carries the same
sentence as a code comment. A hard dependency would have put two contradictory rules in two places -
this project's standing failure mode.

**András's condition: "ha már fent van, akkor a riportok ne törlődjenek."** Verified the same day
rather than assumed, and it holds on three counts:

- **The plugin never touches the block's data.** One single occurrence of `block_configurable_reports`
  exists in the whole PHP tree, and it is the comment quoted above. No write, no delete.
- **`db/uninstall.php` removes two things only** - the draft-editing role and the generations' draft
  question categories. Moodle's own uninstall drops the tables declared in `db/install.xml`, all of
  which are `local_artqtml_*`. A report lives in the block's own table and is not reachable from
  either path.
- **The schema a report selects from is pinned by a test.** `local/model_check_log_test.php`
  asserts the column set of `local_artqtml_modelcheck` verbatim, and separately selects every
  column by name unquoted. `db/install.xml:124` states the same as a contract: renaming or dropping
  a column is a breaking change. So a live report cannot be broken silently by a later upgrade -
  the gate fails first. Today's `pluginversion` column was *appended*, which a named-column report
  does not notice and a `SELECT *` report gains at the end.

**What ships and what does not.** `tools/*` is excluded from the package (verified: 0 entries under
`artqtml/tools/` in `local_artqtml-2026080304.zip`), so the five `.sql` files in `tools/reports/`
are working material in the repository, not an installed artefact. That is consistent with the
decision and needs no change.

**What survives this item.** Only the obligation already carried by the schema test and the
`install.xml` comment. Nothing is left open.

### BL-47 — The `seed` setting promised reproducibility the API cannot give — CLOSED 2026-08-03, removed

**Split out of BL-37 on 2026-08-03 so it is not closed along with it.** Waiting on András's word, and
on nothing else.

**What it is.** `settings.php:99-105` offers an admin field called *Seed*, default 42, described as
*"Used for generation reproducibility. Changing it may produce different results than previous
generations with the same source text and settings."* A second string
(`settingseedchangedwarning`, rendered by `local_artqtml_render_seed_warning_script()`) warns the
administrator when they change it - reinforcing the same belief.

**Why it has to go.** The value reaches the model as a line of prompt text (`Seed: {{SEED}}`,
`db/prompt_defaults.php:60`), because **the Claude Messages API has no `seed` parameter** - it is
absent from the full body-parameter list on the official reference. Measured on 2026-08-03: changing
it from 42 to 77 and re-running the same cell on the same source text gave **two of six questions
word-for-word identical** and no new material. The setting does not do what its own description says.

**What the administrator loses:** nothing. It never worked.

**What they gain:** not spending a day on a control that cannot move. This project just did.

**Two options, and the choice is András's:**

- **Remove it** - the setting, both language strings, the warning script, and the `{{SEED}}`
  placeholder from the shipped prompt default. Needs an upgrade step to drop the stored config.
- **Rename it and tell the truth** - it is a label the model sees, nothing more. Cheaper, but leaves
  a control whose only honest description is "this does approximately nothing".

The first is recommended. A setting that has to be explained away is worse than no setting.

**Related but separate:** BL-44 covers the model list offering models the plugin cannot read. Both
are cases of the plugin's own surface promising something the provider does not support, but they
are different code and different fixes.

**Done the same day it was raised.** Removed from the setting page, the warning script and the
function behind it, `generate.php`'s settings builder, the `{{SEED}}` substitution and the shipped
prompt's `Seed:` line - and from the specification (six places), the field table, the manual, the
walkthrough and the technical annex, with the three `.docx` rebuilt and round-trip verified. The
upgrade step drops the stored config and strips the line from the administrator's own stored
template, but only where that line is exactly what this repository shipped, backed up first.

**The lasting lesson is not about the seed.** The specification already said, in as many words, that
the plugin sends neither seed nor temperature to the API - while the setting's own description on
screen promised it "ensures reproducibility". Two statements in one product, contradicting each
other, and the day was spent believing the one that was on screen. The specification was right.

### BL-44 — The model list offered models the plugin cannot read — CLOSED 2026-08-03, reopened the same evening, closed again on measurement

**Reopened and closed again on 2026-08-03.** The first closure was verified on the Anthropic side
only - 11 models, 11 successes - and the sentence "11 models checked, 0 unusable" was recorded in
the handover and in `dontesek.md` as if it covered the product. It did not: the Gemini half had
never been run. When it was, **all 42 models failed in about 150 ms each**, and every one of them
was struck off the validator's dropdown at once.

**Three defects came out of it, in this order, each hidden by the one before.**

1. **The probe sent Claude's schema dialect to Gemini.** `question_schema::build()` pins each
   question type with `['const' => $typecode]`; Gemini's `responseSchema` is an OpenAPI subset with
   no such keyword, so the API rejected the request before it reached a model. `ai_request` already
   carried a comment describing this exact trap for `additionalProperties` - `const` is the second
   keyword of the same family, and it is now converted to a single-entry `enum` in the one place
   that knows the difference between the two dialects.
2. **The sweep could not finish.** Only visible once the requests stopped failing instantly: with
   a payload the API accepts, each probe is a real generation of several seconds, and the run was
   killed by PHP's execution limit **without writing a single row**. Two causes, both fixed:
   `check_listed_models()` set no time limit (`process_pending_generations` always had), and the
   Gemini catalogue was **42 models of every modality** - music, images, speech, robotics, computer
   use, and deep-research agents that run for minutes. `model_list::GEMINI_NON_TEXT_MARKERS` drops
   21 of them, which is also why the dropdown no longer offers a music model as a question validator.
3. **A busy provider was recorded as a broken model.** `gemini-3.1-pro-preview-customtools`
   answered "currently experiencing high demand" and was excluded until the next version bump.
   Transient failures are now a third result value (`model_check_log::RESULT_TRANSIENT`), written to
   the log so the outage is visible, but ignored when the dropdown is filtered - and they do not
   overwrite an earlier verdict, so an outage cannot re-admit a model that really had failed.

**The final measurement, on screen, 2026-08-03 evening.** 22 Gemini models probed with a real
one-question generation: **12 usable, 9 struck off, 1 transient.** The nine are not the plugin's
doing - Google answers "no longer available" or "no longer available to new users" for the whole
`gemini-2.0-*` family, `2.5-flash`, `2.5-flash-lite`, `2.5-pro` and `3-pro-preview`, and
"only supports Interactions API" for `gemini-omni-flash-preview`. **Every one of those was
selectable as the site's validator before today.**

**The transient path proved itself unforced, which is worth recording because it could not be
staged.** `gemini-pro-latest` succeeded at 18:02 in 38.3 s and timed out at 18:24 after 60 s. Under
the old rule that second reading would have excluded a model the same sweep had already proved
working. It is `transient`, it stayed in the list, and the settings page correctly reports
**21 checked** rather than 22.

**One thing deliberately not fixed, named so it is not rediscovered by accident:** the
*availability* half of the check has the same exposure - `model_list::refresh()` reports only
success or error with no HTTP code, so a 503 from the models.list endpoint is indistinguishable
from "this model is gone", and it would block the site. It has not been observed happening, and
widening that method's return shape is a larger change than the defect that was measured. The
comment at `model_checker.php` says so at the call site.


**Raised by András, 2026-08-03, from a measurement that cost money to discover.** The Generator LLM
tab offers twelve Claude models. Two of them - **Claude Sonnet 5** and **Claude Opus 5** - return a
response the plugin silently throws away.

**The defect.** `generate_questions_task.php:405` reads the answer out of the **zeroth** element of
the response's `content` array:

```php
$text = $decoded['content'][0]['text'] ?? null;
```

The newer models open with a **thinking block**, so the questions arrive in the *second* element.
The plugin sees nothing, retries three times, and fails. The same hardcoded index appears a second
time in `model_checker.php:194`, so a fix has to move both.

**What was measured, 2026-08-03.** Nine calls across two generations (1490, 1491). Every one was
**HTTP 200** with no network fault, every response body carried a `content` array of
`["thinking","text"]`, and the `text` block held **valid JSON with six questions in it**. The models
worked; the plugin discarded their work. Sonnet 5 produced **zero questions for $0.228**. Opus 5
survived only because its JSON-retry happened to start with a `text` block.

**What the teacher sees:** an administrator picks one of the newest models, and every generation
fails with no usable message. The API call is billed regardless.

**And the token counter hides it.** Failed calls are logged with `isretry=1`, and the monthly
counter (`token_budget.php:65-67`) sums only `isretry=0` rows. The $0.228 spent that day **does not
appear** on the Token tab. It appears on the invoice.

**What András asked for, and it is two things, not one:**

1. **The connection test must send a real question.** Today the "Test connection" button on the
   model selector confirms that the endpoint answers. That is not enough - it did not catch this.
   The test has to issue a genuine generation request and **check that the plugin can parse what
   comes back**, so that the button validates the *data structure*, not just the connectivity.
2. **The list must only offer models the plugin can actually read.** A model whose response shape
   the current code cannot handle has no business being selectable. Either the parser is widened to
   accept it, or it is kept out of the dropdown - but the dropdown must not offer a choice that
   silently fails.

**The order matters:** widening the parser is the smaller change and probably removes most of the
problem, but it does not remove the need for the test to prove it. A parser that is fixed today can
be outgrown by a model released next month, and only a test that sends a real question will notice.

**Correction and good news, checked in the source 2026-08-03: most of what point 1 asks for already
exists - it is just wired to the wrong place.**

- **The "Test connection" button does NOT send a question.** `classes/external/test_connection.php`
  issues a single `GET https://api.anthropic.com/v1/models` and checks the endpoint answers. No
  generation request, no schema, no structure check. So the button is exactly as weak as this item
  says.
- **But `model_checker::probe()` already does the right thing.** The nightly `model_check_task`
  (daily at 04:15, both providers) sends a genuine request **with a response schema** and then
  validates the reply against that same schema, returning `modelcheckstructurefailed` when it does
  not fit. Its own comment names the purpose: *"Admin-053: the response is validated with the same
  schema that was sent. A 200 whose body does not satisfy the schema is exactly the 'API structure
  changed' case."*

**So why did the nightly check not catch Sonnet 5 and Opus 5?** Two reasons, both fixable:

1. **It only probes the model currently selected** (`model_checker.php:71` reads the setting), not
   the twelve offered in the dropdown. Neither of those two was ever selected until 2026-08-03.
2. **It reads the reply with the same `content[0]` assumption** (`model_checker.php:194`). For a
   thinking model it would therefore report "structure failed" - the right verdict for the wrong
   reason: the model's structure is fine, ours is too narrow.

**This makes the item smaller than it looks.** Nothing new has to be invented: point the button at
`model_checker::probe()`, run it across the listed models rather than only the selected one, and fix
the shared parser.

**Decided by András, 2026-08-03: a probe-sized schema, not a real generation.** No six-question run
per model - that would cost a generation each and could only ever be triggered deliberately. The
check stays cheap enough to run across the whole list, and often.

**One thing that decision leaves to get right, and it is the whole point of the item.** The probe's
current schema is `{ok: boolean}` (`model_checker::probe_schema()`), which is not a question - it
proves structured output works at all, and nothing about whether a *question* can be read back. A
probe that stays small but asks for **one question in the real question shape** is what actually
tests what broke here: the plugin failed on where the answer sat in the response, not on whether the
model could produce JSON. Small schema, real question - that is the reading of the decision.

**And a caveat that survives any schema choice:** the probe must be read by the *same* code path the
generator uses. If the probe keeps its own parser, it can pass while the generator fails - which is
close to what happened, since the nightly check and `generate_questions_task` share the `content[0]`
assumption but nothing forces them to stay in step.

**Noticed in the same file and worth fixing while it is open (from the 2026-08-03 code review):**
`test_claude()` calls `model_list::refresh('claude')` **and** `refresh('gemini')`, while
`test_gemini()` calls neither. So testing the generator quietly fires a call at the other provider,
and testing the validator leaves its model list empty.

**Closed 2026-08-03, and with it the criterion for v1.** Everything below was built and then walked
through on the running site, as an administrator, with real API calls.

**What the screen says now**, under the model dropdown, from the check log rather than from a
message that could be lost:

> *Utolsó szerkezeti ellenőrzés: 11 modell ellenőrizve, 0 használhatatlan és kikerült a listából —
> 2026.08.3 17:44.*

**The defect is fixed and the fix is proven.** Claude Sonnet 5 and Opus 5 - the two models that on
the morning of that day produced zero questions for $0.228 - now pass the structural probe. Eleven
models, eleven successes, about fifty seconds end to end.

**The four pieces, and why each is where it is:**

1. **One place reads the envelope.** `ai_request::extract_text()` scans for the first block carrying
   text instead of indexing element zero, so a reasoning model's thinking block no longer hides the
   answer behind it. `hit_token_limit()` did the same for the truncation signal, which turned out to
   be a second copy of the same knowledge, spelled differently per provider. A static test now fails
   if either pattern reappears anywhere under `classes/`.
2. **The probe asks a real question.** `question_schema::build()` with a count of one - the
   generator's own builder - and the reply is checked with the generator's own shape test. Passing
   the probe now means the plugin can read this model's questions, not that the model can emit JSON.
3. **A failing model leaves the dropdown**, scoped to the plugin version that judged it, so a defect
   of ours cannot exclude a model permanently.
4. **The button sweeps the list**, one paid call per model, each result written as it happens.

**Three things this cost, all of them worth recording because each was a wrong turn:**

- **A session notification to carry the verdict.** The button's JavaScript reloads the page on
  success, erasing anything returned. Routing the message through the session looked like the fix
  and was abandoned - the verdict belongs on the page, read back from the log, where the reload
  displays it instead of destroying it.
- **A version bump hid the summary.** Scoping it to the plugin version is right, but it left the
  page silent after an upgrade, and a silent settings page reads as "everything is fine". It now
  says the version has not been checked yet.
- **A measurement instrument that always said yes.** For most of the afternoon the run was judged by
  whether the page contained "...", which it always did - the dropdown's own default label is
  *"Választás..."*. Several "the request never returns" conclusions came from that, and the check
  log is what eventually settled it: 11 rows, 33 seconds apart.

**The observability that settled it was a Configurable Report over `local_artqtml_modelcheck`**,
built the same day at András's prompting. Its first use answered in one query what three browser runs
could not. It is not a deliverable of BL-08: that item was closed the same day with the decision that
the report is **not** part of the installation, so this report exists on the development machine and
its query belongs in `tools/reports/`.


### BL-32 — The difficulty level was a label with no definition behind it — CLOSED 2026-08-03, measured under control

**Status 2026-08-02, end of day.** The widening the title asks for has **run and been judged**: 36
generations (`M32-<type>-<S|B><run>`, ids 1378–1415), 6 types x 2 difficulty modes x 3 runs, 6
questions each on the same 5,908-character source text, with BL-29's per-answer explanation on for
IH/FE/FT. **215 questions: 177 fit the level asked for, 30 partly, 8 not.** The validator, on its own
criteria, flagged 29. Full table in `riportok/BL-32_nehezsegi_meres_2026-08-02.xlsx`, reasoning in
`riportok/BL-32_meres_2026-08-02.md`.

**What is left on this item, and only this:** the 2026-08-01 baseline of 181 questions was **not
re-judged** with the same yardstick, though the frozen conditions promised it. Until it is, no claim
about the *size* of the improvement stands — the two samples were scored by different judges against
different rules. Today's 177/215 is valid on its own; "from 45% to 82%" is not.

Four findings from the grid became their own items rather than being buried here: BL-38 (Bloom does
not fit ordering), BL-39, BL-40, BL-41, BL-42. The short-answer defect the grid exposed was fixed the
same day and is described below.

Two operational findings from the run itself, both already acted on:

- **Two cells failed at the validator**, both with the identical `Operation timed out after 60005
  milliseconds with 0 bytes received` from Gemini — not a difficulty result, so both were re-run
  unchanged (1414 replaces 1386, 1415 replaces 1388). The second re-run came back *partly
  successful* with 5 questions instead of 6, so one cell of the grid rests on 5.
- **Each failure discarded questions Claude had already produced and billed for**, and the retry
  called it again from scratch. That is **BL-26** observed live rather than inferred from a log.

**And one finding that is a positive, recorded before the numbers:** the three runs of a cell
produce largely *the same questions*. For the measurement that is what makes three runs meaningful —
they measure the prompt rather than noise. What it leaves for later is a teacher who regenerates
wanting *more* questions and gets the same ones back; that is BL-37, not this item.

**The defect.** Until 2026-08-01 the whole of what the prompt said about difficulty was one line
built in code by `describe_difficulty()`:

```
Difficulty: Easy: 2, Medium: 2, Hard: 2
```

Three labels and three counts. **Nowhere — not in the code, not in a lang string, not on the admin
page — was there a sentence saying what Easy, Medium or Hard mean.** The model was left to invent
the scale, and invented a shallow one: difficulty tracked how obscure the fact was, not how much
thinking the question took. Across 181 measured questions, **72 did not match the level they were
asked for** and 27 matched only partly. The validator never once objected — level fit is not among
its criteria.

Two failure modes recurred, and they are the ones the fix names explicitly:

- **All three levels, one operation.** Easy, Medium and Hard were each "find the sentence that
  contains the answer"; only the topic changed. Short answer (RV) was the extreme case — all 36 of
  its questions were recall, whatever level was requested.
- **Scenario as decoration.** A "hard" or "apply" question was an easy one with a story in front of
  it: *"Ha valaki almapálinkát szeretne készíteni…"* — and the answer was still one sentence of the
  source text.

**The fix (Admin-069, specification v38).** Two new admin-editable prompt settings,
`promptdifficultyscale` and `promptdifficultybloom`, seeded from `db/prompt_defaults.php` by the
standing rule. `describe_difficulty()` no longer writes prompt text; it substitutes the counts into
the chosen fragment through `{{EASY}}`/`{{MEDIUM}}`/`{{HARD}}` and
`{{REMEMBER}}`/`{{UNDERSTAND}}`/`{{APPLY}}`. Each fragment defines its levels as **mental
operations** — Easy locates one sentence, Medium joins two places in the text or spots a
contradiction, Hard weighs several statements or reaches a conclusion the text supports but never
states — and each one closes by naming the two failure modes above so the model is told not to
commit them.

**First measurement, 2026-08-01, on the same source text as the baseline so the comparison holds.**
Four generations, six questions each, on the two types where the baseline was weakest:

| | baseline (3 runs each, n=73) | after Admin-069 (1 run each, n=24) |
|---|---|---|
| level matches the question | 42 (58%) | **21 (88%)** |
| partial | 10 | 3 |
| does not match | **21** | **0** |

**Question shapes appeared that are absent from all 181 baseline questions:** a whole-text inference
(*"A szöveg egésze alapján melyik következtetés vonható le?"*), a proverb asked to be reformulated
(*"Mit fejez ki lényegében a »napi egy alma az orvost távol tartja« közmondás?"*), and a Medium
question that genuinely needs two separate sentences (*"Mi szükséges a bőséges terméshez, és mi
segíti a jobb terméshozamot?"*). FE scored 6 of 6 in both modes.

**What is NOT settled, and the item stays open for it:**

1. **One run per cell is not a measurement.** The baseline has three runs per cell; this has one.
   The direction is unmistakable, the size of the effect is not.
2. **Only FE and IH were *judged*.** RV is the case that most needs it and the one least likely to be
   fixed by a definition: with a one-word answer, "apply" may simply not be expressible, in which
   case the honest answer is to bar RV from the upper levels rather than keep asking.

   **Correction, 2026-08-02.** This item said SR, EH and RV "were not tested". They were: the D69
   series on the running site is eight generations, not four —

   | generation | type / mode | questions |
   |---|---|---|
   | 1314, 1315 | FE scale, FE bloom | 6, 6 — these are the n=24 the 88% was computed from |
   | 1316, 1317 | IH scale, IH bloom | 6, 6 — likewise |
   | **1318, 1319** | **RV scale, RV bloom** | **6, 6 — generated, never judged** |
   | **1320** | **EH scale** | **6 — generated, never judged** |
   | **1321** | **SR scale** | **0 — the run produced nothing** |

   So eighteen questions of the "missing" measurement already exist and cost nothing to judge, and
   SR's gap is not "unmeasured" but "measured and empty", which is a different problem. What is
   genuinely absent: EH bloom, SR bloom, both FT cells, and runs 2 and 3 of everything.

   The mistake this records is worth keeping: the item was written from what had been *analysed*
   and described it as what had been *run*.

   **RV judged, 2026-08-02, and the answer is not a prompt change.** The twelve RV questions from
   1318 and 1319 scored **6 match, 3 partial, 3 no match** against the level definitions the
   generator itself was given — well below the 88% measured on FE and IH, and every one of the three
   misses sits at the **top** level (Hard, Apply). Two of them are the scenario-as-decoration
   pattern the fragment names explicitly.

   But the level fit is the smaller half. RV is `qtype_shortanswer`: the student types a string and
   Moodle compares it, case-insensitively, against **one stored answer** — the AI generates no
   variants and `fraction` is always 100. Read the same six questions from the marking side:

   | level | the stored accepted answer | can a student type it? |
   |---|---|---|
   | Easy | `rózsafélék` | yes |
   | Easy | `Közép-Ázsiából` | yes — but "Közép-Ázsia" fails |
   | Medium | `Bőséges napfény és jó vízáteresztő talaj` | not in the other order |
   | Medium | `pektin` | yes |
   | **Hard** | `Az erjesztett almaléből cider készül, az almacefre lepárlásával pedig almapálinka.` | **effectively never** |
   | **Hard** | `Mert alacsony a kalóriatartalma, és jelentős része víz.` | **effectively never** |

   **Only the easiest questions have a gradable answer**, and that is structural, not a prompt
   defect: the more thinking a question demands, the more ways there are to phrase the answer
   correctly, and this question type accepts exactly one. The two questions that *did* match the
   Hard level are unusable as questions — a student can know the answer and still score zero.

   So RV's half of this item is a **product decision, not a measurement**: keep RV at
   Easy/Remember and disable or warn on its upper cells in the grid. No amount of prompt wording
   changes the arithmetic above.

   **The same suspicion does not carry to EH.** An essay is marked by a person, so there is no
   exact-match constraint, and its levels are worth measuring on their own.

   **The wider measurement is deferred until after BL-29, decided 2026-08-02.** Thirty-six
   generations were queued that afternoon and then discarded before any of them reached the saving
   stage, on András's call, and the call is right: **BL-29 changes the response schema**, and this
   item measures what the schema and the prompt produce together. Anything measured before that
   change would have to be measured again after it. The queued runs were not half-finished work —
   they were avoided waste.

   What this leaves behind, so the sequencing is not rediscovered later:

   - **Measure after BL-29 lands, not before.** The order is not a preference; the earlier order
     produces numbers that describe a system that no longer exists.
   - Everything needed to restart is written down and costs nothing to reuse: the conditions and the
     yardstick in `riportok/BL-32_meres_2026-08-02.md`, the extended source text next to it, the two
     reports in `tools/reports/`, and the Configurable Report (id 6) with pagination off.
   - Yesterday's D69 and A2 generations (1310–1321) are **deliberately kept**. They are the evidence
     behind the RV and EH judgements above; deleting them would leave those numbers unsupported.

   **EH judged the same day: 6 of 6, every level.** The two types are mirror images, and that is the
   most useful thing this round produced:

   | | RV (short answer) | EH (essay) |
   |---|---|---|
   | level fit | **50%** (6 / 12) | **100%** (6 / 6) |
   | where it breaks | the **upper** levels | nowhere |
   | why | one accepted answer string — the more thinking, the more correct phrasings it rejects | marked by a person, no exact-match constraint |

   EH fails at the **other** end, and not on level fit: its two Easy questions (*"Where does the wild
   ancestor still grow?"*, *"Which proverb does the text quote?"*) are one-fact questions posed as
   essays. The level is right; the type has nothing to do, and a teacher has to hand-mark a free-text
   box for a single word.

   So the question type and the difficulty level are **not independent**, and the grid treats them as
   if they were.

   **Decided 2026-08-02: nothing is disabled.** No cell comes out of the grid — the teacher may ask
   for a Hard short answer or an Easy essay, and will get what is measured above.

   **What that decision leaves to solve, because the defect does not go away with it:** a student can
   know the answer to a Hard RV question and still score zero, and nothing tells anyone. The shape of
   the remedy is already settled on this project — BL-31 ended the same way: the generator keeps its
   behaviour, the **validator** names the problem, and the teacher fixes it. The rule to add is the
   short-answer counterpart of Val-033: *an RV question whose accepted answer is a full sentence
   rather than a word or a short phrase cannot be answered exactly, and must be flagged.* Unmeasured
   and unbuilt; recorded here so the decision above does not read as "there was nothing to do".
3. **The scenario-as-decoration pattern survived once** — IH bloom's first Apply question is still
   *"Ha valaki almalét szeretne cidert készíteni…"* with a source sentence as the answer, despite
   the fragment naming that exact mistake. Named is not the same as prevented.
4. **The Hungarian of that same question is broken** (*"Ha valaki almalét szeretne cidert készíteni
   belőle"*), and the validator accepted it. Grammar is outside the validator's criteria — that is
   its own gap, and it appeared in the baseline too.

**Closed by András, 2026-08-03.** The one thing this item still owed - re-judging the baseline with
the same yardstick - was done twice that day, the second time under controlled conditions, and the
item has now delivered what it promised.

**The controlled measurement.** All three weaknesses of the earlier comparison were removed at once:
**the same source text on both arms**, **the same 36 cells on both arms**, and **blind judging** -
the questions were shuffled and stripped of their arm before being scored, and the arm was attached
back only after every judgement was fixed. 72 generations, 431 questions.

| arm | questions | fits the level | partly | does not fit | |
|---|---:|---:|---:|---:|---:|
| **before** (pre-Admin-069 prompt) | 215 | 111 | 34 | 70 | **51.6%** |
| **after** (shipped prompt) | 216 | 157 | 28 | 31 | **72.7%** |

The gain sits exactly where the defect was: **Medium 22% → 58%, Hard 33% → 67%, Apply 19% → 50%**,
while Easy (86 → 94) and Remember (100 → 100) barely moved because there was nothing there to fix.
Per type: IH 44→67, FE 44→78, FT 44→78, SR 63→81, EH 81→94, and **RV 33→39** - short answer barely
moved, which is consistent with what this item already recorded: RV's problem is the question type's
structure, not the prompt. The validator, on its own criteria, moved the same way independently:
"needs revision" fell from 108/215 to 68/216.

**And the number this corrects, which is why the controlled run was worth 87 minutes of machine
time.** The uncontrolled comparison earlier the same day showed 47% → 82.3%, a gain of 35 points.
The controlled one shows 51.6% → 72.7%, a gain of **21 points**. **Roughly forty per cent of the
apparent improvement was not the prompt** - it was the other source text. That is the exact risk this
item's own text had been warning about since the day it was written, now with a number on it.

Reports: `riportok/BL-32_kontrollalt_meres_2026-08-03.xlsx` (comparison, all 431 questions, the blind
list as it was judged, and a method sheet naming what must not be read out of the numbers), plus the
raw exports next to it.

**What the measurement still does not settle, stated so it is not read as more than it is:** one
source text, one judge, and three runs per cell that are not three independent samples - the
generator is reproducible, so the same text yields largely the same questions. And "fits the level"
is not "is a good question": RV/Bloom scored well on level fit while this item's own earlier round
showed those same questions to be unusable for a different reason.


### BL-37 — Regenerating for more questions returns largely the same ones — CLOSED 2026-08-03, answered by measurement

**Closed by András, 2026-08-03, because it and BL-46 are the same subject and the open list was
carrying the phenomenon twice.** This item asked *why* the same questions come back; that is
answered below, and no action follows from the answer. BL-46 is the next step - telling the
teacher - and it is where any further work on this belongs. The one thing that does not travel
with BL-46 is the seed setting's removal, which is now **BL-47**.

**Raised 2026-08-02 from the BL-32 grid, and it is the flip side of something good.** Three runs of
one cell — same source text, same settings, three separate API calls — produced largely the same
questions. In the IH/scale cell, *"Az almafa a rózsafélék (Rosaceae) családjába tartozik"* was the
first Easy question in all three runs, and the cider/pálinka distinction was Hard in all three.

**Why that is a positive first.** It is what makes three runs per cell a measurement rather than
noise, and for a teacher it means two parallel classes get papers of the same difficulty, and
repeating a generation does not return a different quality of set.

**What it costs.** A teacher who generates six questions, keeps four, and generates again to top up
gets the four they already rejected. Nothing on the screen says why, and the obvious reading —
"the AI has run out of material" — is wrong.

**Where the lever already is.** The generation carries a `seed`, substituted into the prompt as
`{{SEED}}` and defaulting to 42. It is not exposed anywhere on the question settings page, and the
BL-32 grid deliberately did not vary it, because the frozen conditions did not call for it.

**Not yet measured, and that is the first step:** run one cell three times with three different
seeds and compare against the three same-seed runs already in the grid. Only then is it known
whether the seed is the answer, or whether the source text simply has a limited number of easy
facts in it — which the BL-31 measurements make a live possibility.


**Measured 2026-08-03, and the item's own premise turned out to be wrong.** This entry said the
lever was already in the code and only missing from the interface. It is not a lever at all.

**First measurement - the seed.** The `seed` setting is an admin field
(`settings.php:99-105`, default 42), not something hidden: `generate.php:127` reads it into the
generation's settings, `generate_questions_task.php:647` substitutes it into the `{{SEED}}`
placeholder, and `db/prompt_defaults.php:60` puts it in the prompt as the plain line `Seed: {{SEED}}`.
It was changed to **77**, saved, and one generation run on the grid's own source text with the grid's
own cell (IH, scale, 2/2/2). Against the three seed-42 runs of the same cell: **two questions
word-for-word identical** (one of them the same in all four runs), two the same fact reworded, two
reworded on topics already present, **zero new material**.

**Why - and it is in the documentation, not in the code.** The **Claude Messages API has no `seed`
parameter**: it is absent from the full body-parameter list on the official reference, where it
would fall between `service_tier` and `stop_sequences`. The plugin's "seed" is a line of text in the
prompt, and the model treats it as one. And the other lever is gone too - from **Claude Opus 4.7
onward, setting `temperature`, `top_p` or `top_k` to a non-default value returns a 400**, with the
migration guide's recommended path being to omit them entirely. The site runs **Opus 4.8**, checked
on the settings page.

**Second measurement - the model.** The same cell, same text, run on **Sonnet 5** and **Opus 5**.
Twelve new questions across two other models, against the four existing Opus 4.8 runs: one
word-for-word identical, ten the same facts reworded, **one partly new** (vitamin A and polyphenols).
All six runs across three models converge on the same four core facts - Rosaceae family, 80% water,
Malus sieversii, the cider/pálinka distillation distinction.

**The conclusion: the source text is the bottleneck, not the call.** A 5,908-character text holds
roughly this many facts that can be turned into a true/false statement. Rejecting four of six does
not create new material; the seventh has nothing to be made of. Anthropic's own wording is that even
`temperature = 0` never produced identical output - determinism was never a parameter question.

**What was deliberately not done, on András's call:** writing a separate prompt per model would be a
development and testing process with no end, and is not worth it.

**Three directions were rejected, with reasons, so they are not raised again:**

- **Segmenting the source text** into 2-3 separate calls: questions about the beginning and the end
  of the text fall out.
- **Over-producing and holding a reserve** (order twelve, show six): the reserve has to be parked
  somewhere, and that is a product of its own.
- **Feeding the already-generated questions back as exclusions:** ruled out by András before the
  research started.

**What replaces this item: BL-46** - tell the teacher the question is a repeat, rather than pretend
the generator can avoid it. Designed the same day, deferred the same day.

**And one thing left open inside this item, waiting on András's word:** the `seed` setting promises
something that does not exist. Its description says it "ensures reproducibility of the generation",
and a second admin string warns that changing it may produce different results - both reinforcing a
belief the API cannot support. It is to be removed everywhere, on his signal. Until then, anyone who
finds it will spend a day on it, as this project just did.

### BL-42 — The validator's wording rule covers answers, and still let a typo through twice — DROPPED 2026-08-03

**Low priority, recorded 2026-08-02 so it is not rediscovered.** This is not a missing rule. The
shipped wording clause (Val-032) says in as many words:

> *Check that the question, **its answers** and its feedback are correct, natural writing in the
> language of the source text - grammatical, idiomatic, and free of words that do not belong.*

In the BL-32 grid the word **`almacece`** appeared in a correct answer twice — once as
*"Az almapálinka az almacece lepárlásával készül"* (FT/skála), and once compounded with a wrong
gloss, *"Az almacecét (almalevet) kinyeri a gyümölcsből"* (SR/Bloom), where the parenthesis also
misidentifies mash as juice. The source word is `almacefre`. Both passed as **No issue**.

**And the same confusion accounts for half the factual errors.** Of the 6 the validator did catch,
three were one mistake repeated: pálinka distilled from *fermented apple juice* rather than from
*almacefre*, in FE/Bloom, SR/Bloom and FT/Bloom. The generator knows the difference — other runs got
it right — but does not hold it.

**Why it is low priority.** Every question passes a teacher before a student sees it, and a
misspelling is the kind of thing a teacher catches at a glance. The reason to keep the note is that
it tells us something about the validator's reliability that a summary count hides: 186 of 215 came
back "No issue", and at least two of those 186 were plainly wrong.

**Where to start, when it is picked up:** find out whether the validator is being *given* the answer
text for every type, or whether some types send only the question. That is checkable in
`validate_questions_task::build_batch_prompt()` and settles whether this is a prompt problem or a
payload problem before anything is rewritten.

**Dropped by András, 2026-08-03: we are not working on this.** The observation stays on record
because it says something about the validator that a summary count hides, and that is the only
reason to keep reading it: 186 of 215 questions came back "No issue", and **at least two of those
186 were plainly wrong**. The reason not to act is the one already written below — every question
passes a teacher before a student sees it, and a misspelling is what a teacher catches at a glance.

If it is ever picked up, the first step is unchanged and costs nothing to redo: find out whether
the validator is *given* the answer text for every question type
(`validate_questions_task::build_batch_prompt()`). That settles whether this is a prompt problem or
a payload problem before anything is rewritten.


### BL-40 — An ordering question asked for the source document's own chapter headings — DROPPED 2026-08-03

**Low priority, recorded 2026-08-02 from the BL-32 grid.** One SR/scale question, at Medium:

> *"Rendezd sorba a szöveg fő fejezeteit (címeit) abban a sorrendben, ahogy megjelennek!"*

Every item is genuinely in the source text, so Val-033 has nothing to object to — and the validator
passed it. But the question is about **the document, not the subject**. A student who understands
nothing about apple growing, and simply scrolls the headings, answers it perfectly.

**It appeared once in 215.** That is why it is low priority and why it is written down rather than
fixed: one occurrence is not enough to tell a systematic failure from a one-off, and a prompt clause
written against a single example usually costs more than it saves. If it shows up again in a later
grid, it has a shape - *a question is not about the material if answering it needs only the
document's layout* - and that is a validator rule, not a generator one.

**Dropped by András, 2026-08-03: we are not working on this.** It appeared **once in 215**, and a
prompt clause written against a single example usually costs more than it saves — that was already
the reason it sat at LOW.

Kept for one reason only: it has a shape, and if a later grid shows it again the shape is already
named — *a question is not about the material if answering it needs only the document's layout* —
and that is a validator rule, not a generator one.


### BL-01 — Screenshots in the functional specification — CLOSED 2026-08-03, they are not going back in

**Closed by András.** The item asked for screenshots to be put into the functional specification once
the interface stopped moving. His reasoning, and it ends the item rather than deferring it again:
**there is no point putting finished images back in.** A specification is read for what the product
must do; a picture of a screen that has already been built adds nothing to that, and it starts
ageing the moment it is pasted in.

What this closes, so it is not reopened by accident: the specification stays text-only, and the
screens are documented where a picture actually earns its place — the user manual, which carries
fourteen Hungarian figures as of v6 (BL-25).

**One consequence worth recording:** this was one of the three items waiting on the undated "v1
freeze" (with BL-08 and BL-10). The criterion arrived on 2026-08-03 - v1 is BL-44 closed - and both
others were answered the same day: **BL-08 closed, decided against**; BL-10 remains, now with a date
somebody can point at.

### BL-23 — A böngészős tesztkészlet kikerült a CI-ból — DROPPED 2026-08-02

Eldöntve 2026-07-30-án: a böngészős job és az őt tápláló változásfigyelő kikerült a `ci.yml`-ből.
A tétel a BL-43-ba olvadt, ami a kézi bejárási listát adta a helyébe; a fájlok 2026-08-04-én, a
maradék könyvtárak 2026-08-06-án kerültek ki a repóból.

### BL-22 — Visual regression — DROPPED 2026-08-02, it is not coming back
Removed on 2026-07-30: seven spec files, sixteen committed baselines, the `RUN_VISUAL` gate, the
`update_visual_baselines` / `baseline_scope` workflow inputs, the regeneration step, the baseline
artifact upload, `docs/VISUAL_TESTING.md`, and the platform-snapshot rules in `.gitignore`.

**The decision, in one line: pixel-exact baselines do not belong on an interface that is still
changing.** They come back when the screens have settled - and then they are generated once,
against a UI nobody expects to move.

**What they cost on the day they were removed.** Twelve failures, every one of them the expected
consequence of a deliberate change, and **not a single regression found**. Establishing that took
five CI runs of 15-20 minutes each and most of an afternoon, and left the working tree in a state
where baselines from two different runs had overwritten each other.

**What the history shows.** D-1 - table headers breaking mid-word - was found by a human looking at
the demo; the visual test then *accepted* the new image, it did not raise it. The four admin
baselines had been stale for two days and 78 commits after a language cleanup, and nobody noticed.
BL-17 records that the licence baseline photographs licence data rather than layout, so every
fixture change fails it. A positional date-column mask silently slid onto the Actions column when a
column was removed, and the spec then failed "while looking like a layout problem". Serial mode hid
three approve cases behind one baseline failure, so a regeneration would have rewritten them
without a diff ever being seen - which is exactly what happened.

**What still guards layout, and is enough for now.** `tests/responsive.spec.ts` asserts at 1440,
1024 and 768 px that no screen overflows horizontally and that the Actions column stays reachable
(Glob-034/035). That is the property that actually matters, and it survives a stylesheet change.
The functional specs assert element counts, visibility and text. `styles.css` and `custom_css.php`
now route to `responsive` in `select-specs.sh` instead of to every baseline.

**When they come back, two things must be different**, both learned here:

1. **No serial-mode describe whose first failure hides the rest.** On 2026-07-30 that is exactly
   what happened: three approve cases sat behind one baseline failure, and a regeneration would have
   rewritten them without a diff ever being seen.
2. **No baseline that photographs environment data.** Absorbed from BL-17 on 2026-07-31, which is
   closed. The licence tab was the case that proved it: `license.visual.spec.ts` captured whatever
   licence the environment happened to have installed - institution name, URL, issue and expiry
   dates, edition. That is state, not code, so the baseline went stale whenever the fixture licence
   changed, as it did on 2026-07-29 when the fixtures were reissued for `localhost`. Nothing in the
   product had moved. `license.spec.ts` already says as much in its own header: the "valid state"
   case can only be exercised against whatever licence the target environment has. **The functional
   test adapts; the visual one froze.**

   **The fix, for when this is rebuilt:** the screenshot call takes a `mask` option. Masking
   the value column keeps the baseline on the panel's *layout*, which is what a visual test is for,
   and stops it tracking licence content. Re-accepting the image on every fixture change is not a
   fix, it is a subscription.

**Done 2026-07-31 — the register no longer describes a suite that does not exist.** Sixteen cases
carried the automation type `[Vizuális (screenshot)]`, naming specs deleted with this item. In
register **v43** they read `[Elhalasztva — BL-22 (screenshot)]`: the case, its requirement and its
steps are untouched, so nothing has to be rewritten when the images come back, but the register no
longer claims an automation that is not there. The cases are TC-List-072/073, TC-Felt-108,
TC-Beal-094, TC-StatusVis-001/002, TC-Jov-081/082/083/084 and TC-Admin-115…120.

Only the automation column moved. The `(Vizuális)` category is unchanged, because
`build_register.py` colours rows from it and refuses to build on a value it does not know - which is
also what proves the edit was mechanical rather than interpretive.

**A correction to this item's own list:** it enumerated `TC-Admin-115/116/117/118/120` and omitted
**TC-Admin-119**. There are six Admin cases, not five, and the register showed sixteen where the
list added up to fifteen. A hand-written enumeration next to a machine-readable file is the same
two-source shape this item is about; the count now comes from the file.


### BL-39 — Multiple choice's "easy" is not easy — resolved 2026-08-02, it is in the manual

**Low priority, recorded 2026-08-02.** In the BL-32 grid, FT/scale scored 18 of 18 on level fit, the
best block of the twelve. That number is honest by the yardstick — the yardstick asks which *mental
operation* a question requires, and at the Easy level each of the four statements is plain recall.

**What it does not capture is the workload.** An FT question presents four statements and the student
must judge every one of them, at every level. An Easy multiple-choice question is therefore four
easy questions wearing one label, while an Easy True/False question is one. Two questions marked the
same difficulty are not the same amount of work, and a teacher building a paper from a mixed grid
has no way to see that.

**Why it is low priority.** Nothing is wrong with the questions; the measurement simply does not
measure this, and neither does the interface. It is worth remembering when someone asks why a test
built from "easy" questions took a class twice as long as expected.

**Done the same day**, and it did turn out to be a sentence rather than a fix: the manual's ch.4
carries a "Mire számíts" box beside the per-type count grid, saying that the level describes the
mental operation and not the time it takes, and that an easy multiple-choice question is four
judgements where an easy True/False is one.


### BL-41 — Nothing tells the teacher to add alternative accepted answers — resolved 2026-08-02

**Low priority, recorded 2026-08-02.** Short answer stores one accepted string, and from today the
generator writes one word into it. One word removes most of the problem, but not the endings:
`Közép-Ázsia` and `Közép-Ázsiából` are the same answer and only one of them scores.

**Moodle already solves this, and it works — measured on the screen 2026-08-02.** The question editor
has a *"Blanks for 3 More Answers"* button; three alternatives were entered (`rózsafélék`,
`Rosaceae`, `rózsaféle`), all saved at 100%, and a student typing any of them scored full marks. The
first one is what Moodle prints as "the correct answer", so the cleanest form belongs first.

**What is missing is that nobody says so.** The approve page does not mention it, the manual does not
mention it, and the teacher has no reason to suspect that the single answer the AI produced is a
setting rather than a fact. The remedy is a sentence in the right two places, not code.

**Done the same day**, in the manual's ch.6, under the row action that opens Moodle's editor - the
place a teacher is standing when it matters. It names the button, says to set every alternative to
100%, and says to keep the cleanest form first because that is the one Moodle prints as the correct
answer.


### BL-38 — Bloom's Understand and Apply do not fit ordering questions — resolved 2026-08-02, it is in the manual

**Measured 2026-08-02 in the BL-32 grid, and it is a limit rather than a defect.** SR/Bloom was the
weakest of the twelve blocks by both judgements at once — 9 of 18 by the yardstick, and 8 of 18
flagged by the validator, its worst block too.

**The reason is structural, not a prompt that can be tightened.** Bloom's levels name mental
operations — recall, explain, apply. An ordering question only ever asks one thing: put these in
sequence. The two meet only where the sequence itself carries the operation:

| level | fit | what the grid showed |
|---|---|---|
| **Emlékezés** | works | 6 of 6. The flowering sequence and the taxonomic nesting are the text's own order. |
| **Megértés** | **does not work** | *"Rendezd sorba a héj színárnyalatait a világostól a legsötétebbig"* carried an Understand label in two runs. Putting colours in order explains nothing — no cause, no effect, no restatement. |
| **Alkalmazás** | works only for real procedures | Making cider, distilling pálinka and planting a tree are genuine processes and fit. *"Rendezd sorba az alma szimbolikus megjelenéseit"* is recall in a costume, and one dieter's reasoning chain could not be ordered coherently at all — the choice preceded the requirement it was based on. |

**Decided by András 2026-08-02: nothing is disabled and nothing is hidden.** The teacher may combine
ordering with Bloom and will get what is described above. What has to change is that **the manual
says it**, so the choice is informed rather than discovered: with ordering questions, Bloom's
Remember level and its Apply level *for genuine step-by-step processes* work; Understand does not,
and Apply degrades into a list wherever the items are not really a sequence. The difficulty-scale
mode has no such limit and is the safer choice for ordering.

**Done the same day.** The manual's ch.1 carries it as a "Mire számíts — Bloom és Sorba rendezés"
box, beside the difficulty-mode explanation where the teacher makes the choice: Remember works,
Apply works for genuine step-by-step processes, Understand does not, and the Easy/Medium/Hard mode is
the safer pick for ordering. Nothing is disabled and nothing is hidden, exactly as decided.


### BL-24 — Cache the Moodle clone itself — closed 2026-08-02, the clone is 12 seconds
**Deprioritised 2026-07-31 — moved to the end of the list.** The reasoning is András's and it is
about this item's own economics: we have now spent more time discussing the saving than the saving
would return. The plugin is not in production, nobody is blocked on CI latency, and the work this
buys is a shorter wait, not a better product. It stays on the list because it is real and measured,
not because it is next.

**Done 2026-07-30 (`c6a79e5`):** composer downloads and the node toolchain are cached in both jobs
(`ci.yml`), keyed on `MOODLE_BRANCH`. Both caches are additive - a miss reproduces the old
behaviour exactly - so neither can turn a run red.

**Measured 2026-07-31 on run #97, the first run that actually hit the caches.** This closes the
"Also unmeasured" question the item carried:

| | static-checks (2m 43s) | phpunit (3m 08s) |
|---|---|---|
| composer cache | 1s | 1s |
| node cache | 1s | 1s |
| **Install Moodle + plugin** | **1m 44s** | **1m 50s** |
| the actual work | lint 1s, phpcs 4s | PHPUnit 40s |

The two caches work exactly as intended - both resolve in about a second - but they were small to
begin with. Against #95 (6m 26s, no cache) the whole run improved by roughly **20 seconds**.

**Where the time actually is: `Install Moodle + plugin`, 3m 34s across the two jobs, 58% of a
6m 06s run.** What was called "the larger saving" yesterday is now a number rather than a claim.

**Closed 2026-08-02, because the clone turned out not to be where that time goes.** The item's whole
premise was that cloning Moodle is the expensive part of that step. Measured directly, by running the
exact command `MoodleInstaller` runs:

```
git clone --depth=1 --branch v4.5.12 https://github.com/moodle/moodle.git
```

**12 seconds, 480 MB of working tree.** Twelve seconds of a 214-second step - under 6%. Caching it
would mean restoring a few hundred megabytes from the Actions cache on every run, which on its own
costs a comparable amount of time, so the net saving is somewhere between nothing and negative.

The mechanics were checked before the measurement, and they would have worked: `moodle-plugin-ci
install --repo` accepts a local directory (`Validate::gitUrl` handles absolute and relative paths and
`file://` explicitly), so a cached clone could have been handed to it. The reason not to build it is
the number, not a missing capability.

**What this leaves.** The remaining ~200 seconds of `Install Moodle + plugin` is Moodle's own assets
step, the database creation and the PHPUnit/Behat initialisation - none of which is a download, and
none of which a cache of the clone would touch. If CI latency ever becomes worth attacking again, the
measurement has to start there, and this item is not the way in. The caveat on the number: it was
measured from a development sandbox, not from a GitHub runner. A runner's network is at least as
fast, so 12 seconds is an upper bound rather than an optimistic one.

**The ceiling, stated honestly:** that step does not only download - it clones, then installs and
provisions a database. A cache removes the download, not the install. **3m 34s is the upper bound,
not the expected gain**, and how much of it is download has not been read out of the step log.

**Still not done, and still for the same reason:** whether `moodle-plugin-ci install` tolerates a
pre-populated Moodle directory, or whether the tree has to reach it by a different route. That has
to be established before the step is written, not after.

**What makes it cacheable at all** is BL-20: the branch is pinned to the tag `v4.5.12`. Against a
moving branch a cached clone would go stale within days with no key that detects it. Against a tag,
the tag *is* the key.

**Noticed on the same page, belongs to BL-05b:** run #97 emitted two warnings - `actions/cache@v4`
targets Node.js 20, which is deprecated and is being forced onto Node.js 24. The action version
bump is no longer hypothetical; CI now says it out loud.


### BL-15 — The local static checks stopped being run when CI arrived — resolved 2026-08-02
Three phpcs failures on 2026-07-29, each found one push cycle after it was written, all three the
same PSR12 rule. **Both earlier framings of this item were wrong.** The checks do not live only on
CI, and nothing needs installing: they were set up and run on this machine on 2026-07-25, and both
runs are written down.

**2026-08-02: the friction that killed the habit turned out not to be the running.** `tools/check.sh`
had already made the commands one line, and the gates still ran nine times that day — but each run
had to be copied out of a terminal by hand to be read, and an eighty-line phpcs+phpmd block is worse
to move than to produce. Twice the copy silently truncated: once six phpcs errors vanished from the
list, once a run reported two errors and showed one.

`tools/gates.sh` answers that half: one command for all three gates, the full output written to
`tools/.gates-last.txt` (gitignored) instead of the terminal, and the PHPUnit environment
re-initialised only when `version.php` has moved since the last run — so the one-minute wait happens
when it is needed and not otherwise.

**The fourth step is done, 2026-08-02, and it turned out to be free.** `ignore_warnings_on_exit`
left `phpcs.xml`, so a warning now fails the gate exactly as an error does. It had been deferred to
the release pass on the reasoning that the existing warnings must be cleared first and clearing them
on a moving tree is work that gets redone - but the gates ran nine times that day and the last run
reported **0 errors and 0 warnings**. There was nothing left to clear.

**Verified by breaking it on purpose**, not by the green run: a 149-character comment line was added
to `observer.php`, the gate went red with `WARNING | Line exceeds 132 characters`, and the line was
removed again. A clean run proves nothing about a rule that only fires on dirt.

**What it buys, from the same day.** Twice a warning was the only thing between a correct-looking
file and a failing test: phpcs said the new language keys were out of C-sort order, exited 0 because
they were warnings, and `lang_parity_test` then failed in PHPUnit a minute later - the same fact,
reported slower and less precisely. `tools/check.sh` and `tools/gates.sh` were updated to match, so
the summary now surfaces warning lines instead of leaving the terminal blank under a red gate.

**What remains is the habit**, which no script fixes: running the gates before the push rather than
after. The item closes because everything buildable in it is built; the habit is a practice, not a
task.

Both records left the repository on 2026-07-30 and live in the working-documents folder; see
`docs/README.md` for where it is.

- `riportok/result.md` — PHPUnit 46/46 green, and *"PHPCS (moodle standard): 0 errors, 0 warnings
  on all new files"*. Runner: `docker compose ... exec webserver php vendor/bin/phpunit --testsuite
  local_artqtml_testsuite`, after a one-off `admin/tool/phpunit/cli/init.php`.
- `archivum/REFACTOR_RUN_LOG.md` — PHPStan level 5 run three times over the god-class refactors
  against a recorded 12-error baseline, plus PHPMD on the three target files.

The tooling is present, and `~/projektek/moodle/vendor/bin/` holds `phpcs`, `phpcbf`, `phpstan`,
`phpmd` and `phpunit`. `~/projektek/moodle` is the tree the Docker webserver serves, so the
container is the PHP runtime for all of them.

**Correction, 2026-07-30: an earlier version of this entry said Moodle's own `composer.json`
requires those packages. It does not.** `git show HEAD:composer.json` on the Moodle tree lists
PHPUnit, Behat and the Mink drivers in `require-dev` and nothing else. The four static-analysis
packages were added to Moodle's file by hand, together with a `config.allow-plugins` block. That is
BL-21.

**So this is not a tooling gap, it is a lapsed habit.** The checks were the gate while there was no
CI; once CI existed it became the gate, and the local run quietly stopped - which trades a
30-second check for a 16-minute round trip.

The two commands, matching what CI actually runs (`.github/workflows/ci.yml:210` and `:218`):

```
# from ~/projektek/moodle-docker
docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec webserver \
  php vendor/bin/phpcs --standard=local/artqtml/phpcs.xml local/artqtml

docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec webserver \
  php -d memory_limit=2G vendor/bin/phpstan analyse \
  --configuration=local/artqtml/phpstan.neon --no-progress
```

**Steps to close:**

1. ~~Run both once and record what they say today.~~ **Done 2026-07-29.** Both are clean on the
   current tree: phpcs **0 errors** (several hundred warnings, which the ruleset deliberately does
   not gate on), phpstan **`[OK] No errors`**. The 12-error PHPStan baseline in the refactor run log
   is therefore historical - the `ignoreErrors` entries added to `phpstan.neon` since have absorbed
   it, and **the baseline for both checks is now zero**. That is the number to compare against, and
   it needs no interpretation: any output at all is new.

   The same run also confirmed the checks agree with CI: phpcs reported exactly the picture CI #83
   did, minus the six errors fixed in this commit.
2. Put both in `tools/check.sh`, so the invocation is not retyped from memory. `tools/` is already
   excluded from phpcs, from phpstan and from the shipped zip, so nothing else changes.
3. Decide the trigger: by hand before each push, or a git `pre-push` hook. Time it first - a hook
   slow enough to be annoying gets bypassed with `--no-verify`, which is worse than no hook.

**A fourth step, new on 2026-07-30: the warnings are gone, so the gate can move.**
The report was cleared that day - 40 over-length lines, 65 inline-comment warnings, four
commented-out-code warnings and four unnecessary `MOODLE_INTERNAL` checks, all fixed, and a
following phpcs run came back completely empty. Two of the 65 were found only by that run: a
reimplementation of the sniff used to locate them had looked at line-leading `//` only and missed
trailing comments (`$form->layouttype = 0; // qtype_ordering_question::LAYOUT_VERTICAL.`). With the
count at zero, `ignore_warnings_on_exit` has stopped protecting anything and started hiding
regressions: the next warning will sit unnoticed in a report nobody reads to the bottom. Removing
that line from `phpcs.xml` would make the gate "zero warnings" as well as "zero errors", which is
only maintainable *because* the backlog is empty. Decide it deliberately, not by drift - and note
that CI would then fail on a warning too.

**Decided 2026-07-31: deferred to the final release, alongside BL-25.** `tools/check.sh` confirmed
the state on the current tree - phpcs **0 errors, 0 warnings**, phpstan `[OK] No errors` - so the
gate could move today at no cost. It is being held back anyway, and the reason is what the change
is worth rather than what it costs: phpcs warnings are formatting - line length, comment casing,
commented-out remnants. None of them is a defect, none reaches the screen. The *errors* this item
was born from (three PSR12 failures on 2026-07-29) are caught by the error level and by phpstan,
which gate today regardless of this line.

**So step 2 was the part that mattered, and it is done.** `tools/check.sh` moves the catch from one
push cycle later to before the push. The remaining step is bookkeeping.

**At the final release, with BL-25:** remove `ignore_warnings_on_exit`, so the shipped codebase is
gated on zero warnings as well as zero errors. Do it when the tree is otherwise still - the cost of
the change is that a new `moodle-cs` release can then turn an unchanged tree red, which is
tolerable at release time and a distraction during development.

**Two caveats to carry:**

- **A plugin version bump invalidates the local PHPUnit environment.** Found 2026-07-30, after the
  third bump of the day: `phpunit` refused with *"Moodle PHPUnit environment was initialised for
  different version"*, which reads as a Moodle problem and is not one - the Moodle tree was pinned
  and had not moved. `lib/testing/classes/util.php::is_test_data_updated()` compares
  `core_component::get_all_versions_hash()`, and that hash covers **every** component's
  `version.php`, this plugin's included. So a local run after a version bump needs
  `admin/tool/phpunit/cli/init.php` first, about a minute. Worth knowing before it discourages the
  habit this item is about - and worth remembering that a test-only change needs no version bump,
  so it costs nothing.
- `phpcs.xml` sets `ignore_warnings_on_exit`, so a clean exit still leaves warnings in the report.
  The gate is errors only, exactly as on CI - do not read a warning list as a failure.
- CI downloads the **latest** `phpstan.phar`, while `vendor/bin/phpstan` is whatever
  `composer.lock` pinned. A new PHPStan release can therefore still surface something that was
  green locally. The local run narrows the gap, it does not close it.


### BL-25 — The user manual — rewritten as v6 with Hungarian screenshots — resolved 2026-08-02

**Status 2026-08-02.** The manual is now **v6** (`docs/felhasznaloi_kezikonyv_v6.md` and
`MoodleAI_kerdesgenerator_kezikonyv_v6.docx`, round-trip verified). Everything the entry below asked
for is done, plus the findings from the same day's measurement:

- **Removed** the Tags action, and the "the prompt template is stored encrypted" security box in
  ch.8 — Admin-066 ended that deliberately, and the box promised a protection that no longer exists.
- **Rewritten** the SR item count as a ceiling rather than a quota, with the measured numbers.
- **Added** the per-answer explanation (BL-29), the one-word short answer (BL-32), the prompt-fragment
  admin fields, and — new, and nowhere documented before — **how a teacher adds alternative accepted
  answers** for short answer, verified on the screen the same day.
- **Added five "Mire számíts" boxes**, each backed by measurement rather than assumption: which
  difficulty mode to prefer and why, the question form deciding which levels can be asked, Bloom with
  ordering (BL-38), multiple choice's "easy" not being easy (BL-39), and short answer's upper levels
  (BL-32). BL-41's guidance went into the editing section.

**All fourteen figures were retaken** from the Hungarian interface in one sitting, at one window
size, including a new figure 14 showing the per-answer explanation switch on a question type panel.
Nothing of this item is outstanding.

### BL-25 (original entry) — The user manual still documents the removed Tags action
Found 2026-07-31 while making the specification follow the code. `docs/felhasznaloi_kezikonyv_v5.md`
still lists, under "Soronkénti műveletek": *"Tagek: megnyitja a Moodle natív tag-kezelőjét"*. The
link went on 2026-07-30; the specification and the register caught up today (v34, v42); the manual
did not.

**Deliberately deferred, decided 2026-07-31:** the manual is refreshed **in one pass when the
product is finished**, not per change. It carries 13 embedded screenshots, and any of them showing
the approve page's Actions column would have to be retaken — the same reason BL-01 defers the
specification's screenshots. Doing it now buys a correct sentence and a stale picture.

**What this costs until then:** a teacher reading the manual is told about a button that is not on
the screen. The plugin is not in production, so no one is reading it yet.

**What to check when it is done:** whether any of the 13 images shows the per-row action links, and
whether other removals since v5 left the same kind of trace.

**A second item for the same pass, added 2026-08-01 (BL-31).** Line 382 describes the SR item count
as *"hány elemet generáljon az AI a Sorba rendezős kérdésekhez"* — a quota, which is what it was.
It is a **ceiling** now: where the source text supports fewer items, the question is built with
fewer, and the text wins. Specification v37 (Admin-036) already says so; the manual does not. The
sentence needs rewriting, and the surrounding paragraph checking for the same reading.

**And it needs the warning the interface now carries.** `settingsritemcount_desc` and the new
`sritemcountoverride_help` both say it: an ordering question needs that many items the source text
*genuinely sequences*, the generator returns fewer questions rather than padding a list, and a short
text therefore yields fewer ordering questions than were asked for. Measured — on a 2,565-character
source the generator returned 4 ordering questions out of 24 requested; on a 4,836-character source,
14 out of 24, every one with four real items and no filler. The manual is the place a teacher will
look after seeing that happen, so the same guidance belongs there, with the practical remedy: lower
the item count, or upload a longer text.

**Two sections were written on 2026-08-02, out of band, on András's instruction.** BL-29's
per-answer explanation is documented while it was fresh: a bullet in ch.4 "Részletes beállítások
kérdéstípusonként" (what it does, that Moodle shows it only beside the option the student picked,
why it is off by default, why the switch is absent for SR/EH/RV, and the True/False exception), and
one in ch.10 for the admin default. The docx was rebuilt and the round-trip verified. **This does
not close BL-25** — the Tags sentence, the SR wording above and the 13 screenshots are untouched.

**A third item found the same day, while writing the above.** Ch.8 still says the prompt template
*"titkosítva kerül tárolásra az adatbázisban"*, in a box headed "Biztonsági megjegyzés". Admin-066
ended that: the whole prompt now lives in admin settings in plain text, deliberately, so an
administrator can read and edit it. The box does not need softening, it needs deleting — it promises
a protection that was removed on purpose.

**This is not the only thing waiting for the release.** BL-15's fourth step - removing
`ignore_warnings_on_exit` from `phpcs.xml`, so the gate becomes zero warnings as well as zero
errors - was deferred to the same moment on 2026-07-31, and for a related reason: both are worth
doing once, on a still tree, rather than repeatedly while the code is moving. Whoever picks up the
release pass should take them together.


### BL-29 — The plugin could not give a per-answer explanation — resolved 2026-08-02

**Raised 2026-08-01**, when a test matrix asked for "an explanation for the correct and for the
incorrect answers, per question". It cannot be produced today, and the reason is in three places
rather than one:

| where | what it holds |
|---|---|
| `question_schema.php` | the only AI-written explanation is **`generalfeedback`** — one per question, max 250 characters, shown *whatever* the student answered. The FE/FT `options` items carry `text` and `correct` and nothing else, so there is no field for the model to explain an option in. |
| `question_form_builder.php:255` | `$feedbacks[] = ['text' => '', …]` — Moodle's own per-option feedback column is filled with an empty string for **every** option, on every generation. |
| `settings.php:348-369` | `correctfeedback` / `incorrectfeedback` (and `feedback_ft_partial` for FT) come from `feedback_template()`, which reads an **admin setting per question type**. Every FE question in the site therefore shows the identical sentence when answered correctly. It is a template, not an explanation. |

`apply_ordering()` sets all three to empty outright, so SR has not even the template.

**What a teacher gets instead:** `generalfeedback` — genuinely per-question and genuinely about the
material — plus `hint1`/`hint2` when the hint switch is on for that type. What is missing is the
part that says *why the option you picked is wrong*, which is where a distractor earns its keep.

**Why this is not a one-line change.** The schema, the importer and the form builder all have to
learn the field together, and the `anyOf` fragment for FE/FT is what the Structured Outputs call
enforces — a property added on one side and not the others fails at import rather than at the API.
It also raises a real question the matrix does not answer: whether **every** option gets an
explanation or only the incorrect ones, and what IH does, where the "options" are true and false.

**Where it does not apply:** EH (essay) is manually graded and has no wrong answer to explain, and
RV (shortanswer) matches a single string. The item is about FE, FT, SR and IH.

**Decided 2026-08-01, for the matrix itself:** run with what exists — `generalfeedback` and the two
hints — and record the gap in the output rather than working around it. The table doubles as this
item's evidence.

**Built and measured 2026-08-02.** The open questions above were answered by András: **every**
option gets an explanation, not only the wrong ones; the scope is **IH, FE, FT** — the three types
where Moodle has a field to put one in; the switch is **per generation and per type**, defaulting to
off; and for IH the AI text overrides the Admin-022 feedback template. SR was taken out and its
switch **hidden rather than greyed out**, deliberately: `qtype_ordering` keeps one combined feedback
for the whole question, so a generated per-item explanation would be discarded on import, and a
disabled control would suggest the feature exists there and is merely unavailable.

Verified on generation 1376 (extended apple source text, one easy question per type): all three
types produced usable per-option text, and the question preview shows the explanation **only beside
the option the student actually selected**.

**One finding worth its own line.** On IH the first round produced the same sentence three times —
once for True, once for False, once as general feedback. That is structural, not a prompt that was
ignored: two options resting on a single claim leave nothing else to say. Fixed the same day by
asking for something different rather than asking harder — a separate admin setting
(`promptoptionexplanationtruefalse`) appended only for IH, which asks for the **misreading** a
student who chose that option most likely made instead of for the claim.

**Measured the same day, and it did not fix it.** Generation 1377, three IH questions on the same
source text: the clause worked on **one of three** — the medium question named the half-truth that
misleads (*"Bár a vad ős Kazahsztánban él…"*). The easy and the hard one restated the fact exactly
as before. On an easy True/False question there is nothing to name: the claim stands verbatim in the
source, so no misreading exists to describe.

**The measurement also showed the target was wrong.** The preview settles it: a student never sees
both explanations, only the one for the option they picked. The True/False duplication is therefore
invisible. What a student actually sees is the per-option explanation and the **general feedback**,
one under the other, saying the same thing — and that pair is what the clause never addressed.

**Decided by András 2026-08-02: leave both, and keep the clause.** The duplication is accepted for
easy questions; the clause earns its keep on the harder ones, where it measurably did something. The
route not taken, recorded because it is the obvious next move if this ever becomes annoying: suppress
`generalfeedback` for IH when the explanation switch is on, leaving the student exactly one sentence.
A teacher can already do this per generation by turning the feedback switch off for that type — the
manual says so.


### BL-36 — Nothing connected `amd/src` to `amd/build`, and CI did not notice — resolved 2026-08-02

**Found 2026-08-01, twice in one day.** The AMD source and the built file are two separate files in
the repository, and the only thing that keeps them in step is somebody remembering to run
`grunt amd` in the Moodle root. Nothing in CI builds them, and nothing compares them: a commit that
edits `amd/src/status.js` and forgets the build is green, and the browser keeps running the old
behaviour. That is exactly what happened this morning (the shipped `generatesettings.min.js` had to
be replaced by hand with the unminified source, because grunt was not reachable) and again this
afternoon, where a fixed reload loop kept looping until the caches were purged and the rebuilt file
was actually served.

The cheap fix is a CI step that runs the build and fails if `git diff --exit-code amd/build` is not
clean. It needs Node >=22.11 <23 in the workflow, which is also worth pinning: the local machine had
26.5.0 and grunt refused to start.

**Built 2026-08-02, in the static-checks job, after "Install Moodle + plugin".** Two steps: run
`npx grunt amd --root=local/artqtml` in the installed Moodle tree, then `git diff --exit-code --
amd/build` in the checkout, with an error message naming the command to run locally.

**The node version is deliberately NOT pinned in the workflow.** Moodle's own `.nvmrc` (`lts/jod`)
decides it and moodle-plugin-ci installs from that; a version written into `ci.yml` as well would be
a second source for one fact — the shape this project has removed from the specification, the test
register and the backlog itself. The step instead *checks* the version that is there and fails with
a sentence saying so, because otherwise the symptom is grunt refusing to start, which reads like a
broken build rather than a wrong toolchain.

**One thing the step does that looks redundant and is not:** it copies the built files back over the
checkout before diffing. Where moodle-plugin-ci symlinks the plugin into the Moodle tree that is a
no-op; where it copies, it is the only thing that makes the comparison mean anything. Written
unconditionally so the check cannot silently pass under one of the two arrangements — a guard that
quietly does nothing is worse than no guard, because it is also believed.

**Verified locally, including the failing direction** (rule: produce the broken state on purpose):

| step | result |
|---|---|
| `--root=local/artqtml` produces the same output as the documented local command | byte-identical |
| edit `amd/src/status.js`, do not build, diff | clean — which is exactly today's silent failure |
| run the build, diff again | **`amd/build/status.min.js.map` differs → the step fails** |
| restore the source, rebuild | tree clean, source byte-identical to the backup |

**Ran on CI 2026-08-02, run #106, green — and the log shows it did the work rather than passing
quietly:**

```
node: v22.23.1 (Moodle .nvmrc: lts/jod)
>> Setting root to …/moodle/local/artqtml
Running "ignorefiles" task
Running "eslint:amd" (eslint) task
Running "rollup:dist" (rollup) task
Done.
```

The version check reads `.nvmrc` and prints both values, so a future mismatch arrives as a sentence
rather than as grunt refusing to start. `--root` resolved to the plugin inside the Moodle tree, and
rollup ran. Three seconds, which is the runner being fast, not the step being skipped — the task
names above are the proof of that.

**An unplanned benefit:** `grunt amd` runs `eslint:amd` on the way, so CI now lints the AMD source
as well. Nothing did before.

**Still unproven on a runner: the failing direction.** Both directions were measured locally, but CI
has only ever seen the passing one. That will settle itself the first time somebody forgets the
build — which is the step's whole job.


### BL-28 — The approve page keeps showing the version the teacher edited away from — resolved 2026-08-02

**Cause found and the open question answered, 2026-08-02. This is not a display defect: it is a
data-loss path, and it has a single cause.**

`db/events.php` subscribes to exactly one event, `\core\event\question_updated`. **The question
editor never fires that event.** Read out of core rather than guessed:
`question/type/questiontypebase.php:554` — `question_type::save_question()`, the method every save
from the native editor goes through — fires `\core\event\question_created`, *every time*, because
versioning makes each save a creation. `question_updated` is fired in exactly two places in core,
and neither is the editor's save path: `question/bank/editquestion/classes/external/update_question_version_status.php:97`
(draft/ready status change) and `question/bank/viewquestionname/lib.php:68` (inline rename in the
bank list).

So `observer::question_updated()` has never run on a normal edit. Everything it was written to do
is therefore not happening:

- the stored `questionbankid` is not re-pointed at the new version (the observer's line 80 comment
  describes the fix that never fires);
- the stale Gemini verdict is not cleared — an edited question keeps its old *Accepted*;
- a prior approval is not revoked;
- the *Edited* badge never appears, and `lasteditedby` / `lasteditedat` stay empty;
- no `question_edited` log row is written.

**Measured on generation 1329, question `B30FIX-FT-0001` (id 2120), with all caches purged
beforehand so the observer cache could be ruled out:**

| step | what happened |
|---|---|
| edit the question from the approve page, rename to `… SZERK1`, save | new version created, id **2162** |
| approve page, reloaded | name `B30FIX-FT-0001`, verdict *Accepted*, **no Edited badge**, edit link still `id=2120` |
| `local_artqtml_log` (report 1) | **no new row** — newest entry still yesterday's |
| open the approve page's edit link again | the form shows `B30FIX-FT-0001` — **the pre-edit text** |
| rename to `… SZERK2` and save | a new current version is created from the *old* content; `2162` (`SZERK1`) becomes a side branch |

**That answers the question this item was holding open: a second save does lose the first edit.**
Not because anything overwrites it — because the teacher is handed the old version to edit from.

**Two separate repairs, and they are not the same size.**

1. **The event name — built and verified on the screen, 2026-08-02.** `db/events.php` now subscribes
   to `\core\event\question_created` as well, both events routed to `observer::question_saved()`.

   The ordering trap turned out to be worse than "the row may not exist yet": `save_questions_task`
   does create the Moodle question before inserting the plugin row, but the whole save runs inside
   one transaction, and Moodle holds external observers back until it commits
   (`lib/classes/event/manager.php:110-146`). By the time the observer runs, the row is there and
   looks exactly like an edit. The discriminator is the stored id itself — on the plugin's own
   creation `questionbankid` **is** this question, because the task wrote the id it had just
   created; a teacher's save always produces a new one. Nothing about timing is relied on.

   **Verified on generation 1329, question `B30FIX-FT-0003` (id 2122), after the plugin upgrade:**

   | | before | after the save |
   |---|---|---|
   | edit link | `id=2122` | **`id=2165`** — the new version |
   | validation cell | *Accepted · No issue · …* | **Edited** |
   | Edited badge | absent | **present** |
   | `local_artqtml_log` | nothing | **`question_edited`, row 971** |
   | opening the edit link | the pre-edit text | **the teacher's own edit** |

   A neighbouring untouched row (`B30FIX-FT-0004`) still reads *Accepted*, so this is not a blanket
   wipe. Five PHPUnit tests cover it (`tests/observer_test.php`), and the first of them asserts the
   *wiring* — the event name — because that is the assertion whose absence cost this item. It earned
   its keep immediately: it caught a missing column in the observer's own SELECT on the first run.

   **The `edited` flag now also survives an approval:** an edited question loses its approval, so it
   must be re-approved before it can be moved. That was always the intent; it had simply never run.
2. **The displayed content — built and verified on the screen, 2026-08-02.** András's decision: the
   stored copy stays as the record of what the AI produced, and what is displayed is resolved from
   Moodle at read time. Derived, not stored twice, so the two cannot drift — the same rule the
   question grid follows for its counts.

   `local\approve\current_question::data_for()` loads the live question through
   `question_bank::load_question()` and maps it back into the stored JSON's shape, so
   `question_details_html()` did not have to change at all. The stored copy remains as the fallback
   for a question deleted from the bank afterwards.

   **The name cell was never the problem.** It shows `questioncode` — the plugin's own
   `SHORTNAME-TYPE-NNNN` identifier, which is meant to be stable and is not what a teacher edits.
   The 2026-07-31 report read a renamed Moodle question against that code and concluded the list was
   stale. What is actually stale, and what a teacher actually reads before pressing Approve, is the
   **expandable detail panel**: the options, the items, the hints and the general feedback.

   **Verified:** an answer option rewritten to `ÁTÍRT VÁLASZOPCIÓ` in Moodle's editor now appears in
   the panel, the option it replaced is gone, and a neighbouring untouched row is unchanged.

   **Two things this round got wrong first, both caught by looking at the screen rather than by
   reasoning:**

   - An earlier attempt to prove the panel was stale edited an answer option *that never saved* —
     the TinyMCE editor overwrote the textarea on submit. The conclusion drawn from it ("the panel
     shows the pre-edit option — verified") was not verified at all; it observed the absence of a
     string that never reached the database. The real proof came from the markup: after the change
     the panel carried `<p>` tags, which the stored plain-text JSON never had.
   - Which immediately exposed a defect of this change's own making: Moodle stores these fields as
     HTML, the panel escapes what it renders, so `<p>Bőséges napfény</p>` was printed literally on
     the screen. `current_question::plain()` strips it, keeping this class's promise that what it
     returns is in the shape the stored JSON was.

   **Six PHPUnit tests** cover it (`tests/local/approve/current_question_test.php`), including the
   "no markup comes through" assertion the screen forced.

   **The docblock's stated reason for reading the stored copy did not hold.** It said this "still
   works for a not-yet-imported/rejected row too" — but a row only exists once
   `question_importer::create()` has returned an id, and a semantically rejected question never gets
   a row at all (`save_questions_task::save_all()` logs it and moves on). Every row has a Moodle
   question. What can happen is the reverse, and that is what the fallback is now for.

**What was left behind on the dev site by this measurement:** in generation 1329,
`B30FIX-FT-0001` now has versions named `… SZERK1` and `… SZERK2`, and `B30FIX-FT-0002` has
`… SZERK1`. Test data in a draft bank; harmless, and worth keeping until the fix is verified
against it.

---

**Found 2026-07-31 by editing a question on the approve page and saving it.** Moodle 4.x versions
questions: saving does not overwrite the row, it writes a **new `question` row** in the same
`question_bank_entries` and leaves the old one in place. The approve page stores the **question id it
was given at generation time**, so after one edit it is pointing at a version the teacher has already
moved on from.

**Measured on generation 1257, question `ALMA4-FE-0001` (id 1796):**

| where | what it shows |
|---|---|
| the edit form after saving | `ALMA4-FE-0001 SZERKESZTVE`, v2, id **1799** |
| the approve list, reloaded | `ALMA4-FE-0001` — the old name |
| the approve list's *Szerkesztés* link | `question.php?id=**1796**` — v1, „1 változat" |
| the target bank after the move | `ALMA4-FE-0001 SZERKESZTVE`, **v2** ✅ |

**The move itself is right.** It follows the bank entry, so the edited version is what reaches the
teacher's bank. This is a display and link defect, not a data-loss one — *as far as it was measured.*

**Two consequences, of different weight:**

1. **The teacher gets no confirmation.** They edit, save, land back on the approve page, and see the
   text they just replaced. Nothing says the edit took. The obvious reaction is to edit again.
2. **And editing again starts from the old version** — verified: the link opens v1's content, with
   „1 változat" in the form. **Whether a second save then loses the first edit was not tested**, and
   it should be before this is sized. If it does, this stops being cosmetic.

**Where to look:** whatever the approve page joins on to list its questions, and the `Szerkesztés` /
`Előnézet` URLs it builds. The fix is to resolve the *latest version* of the bank entry rather than
the stored question id. `Előnézet` was not checked and may have the same shape.

### BL-02 — Review the documentation's formatting — resolved 2026-08-02

**Done, and the round trip proves it.** All thirty tables are markdown tables again (0 flattened
left, 247 real table rows against the 25 the file had), all 368 HTML entities are decoded, and the
nine JSON/PHP examples are fenced. `build_docs.py` learned the code block — the language travels in
the paragraph style's name (`AIQ Code json`), so the reverse direction can rebuild the fence — and
the field table's 90 blockquote lines became paragraphs, matching what the specification and the
register already did. **All three documents now pass `md -> docx -> md` with no difference**, and
the annex has a `.docx` for the first time.

Two structural defects surfaced only because the round trip refused to pass, neither of them
formatting: a `## 8.3` section sitting before chapter 1 (import residue), and a one-row table with
no header floating between two paragraphs in the field table. Both fixed. And one the check caught
on me: a note I had inserted into the middle of a table, splitting it in two.

The measurement below is what the item looked like before, kept because the numbers are the record
of how bad the import damage was.

---

The specification and the test register are generated now (`tools/build_docs.py`,
`tools/build_register.py`), so this is down to the technical annex and the field table, which are
still maintained by hand.

**Measured 2026-07-31, and it is not a formatting item.** `technikai_melleklet_v18.md` **has lost
its tables.** Thirty of them are flattened into consecutive paragraph lines - the header cells
appear as a run of bold-only lines, then the data cells follow one per line, with no pipes anywhere:

```
**Mező**
**Típus**
**Leírás**
model
string
Admin oldalon konfigurált modellazonosító (Admin-012)
```

Real markdown tables begin only at **line 978 of 1053** - the sections written since the document
moved into the repository. Everything before that is import residue: the file came out of Word and
the table structure did not survive. The same import left **368 HTML entities** in the prose - 340
`&quot;`, 23 `&gt;`, 5 `&lt;` - so a JSON example currently reads
`{ type: &quot;json_schema&quot;, schema: {…} }`.

**What this costs:** in any markdown viewer those thirty tables read as a meaningless run of lines,
and the annex is the document the field table and the specification both cite for technical detail.

**Also measured, and much smaller:** the field table (`mezotabla_v13.md` since 2026-08-01) is intact - 283 table rows when measured on v12, all real. Its
only issue is 47 blockquote lines, which are valid markdown but fall outside `build_docs.py`'s
supported set, so the file could not be converted to .docx today. Neither file is in the converter's
canonical form.

**The repair is mechanical but not blind.** The pattern is N bold header lines followed by data
lines in groups of N, so a reconstruction can be checked: every rebuilt table must have a row count
divisible by its column count, and the total line count must be preserved. A cell containing a line
break would break the grouping, so each table needs looking at, not just running a script over the
file. Thirty tables is real work, and a wrong split corrupts documentation silently - which is why
this is written down before it is started rather than after.

### BL-35 — Per-type generation inside one work session, and the "partly successful" state it needs — resolved 2026-08-01

**Decided with András 2026-08-01.** A generation containing several question types is split into one
API call per type, generating and validating. **It stays one generation** — the teacher uploaded one
text and is doing one piece of work, so one row, one status page, one approval list. What changes is
what happens underneath, and what the interface has to be able to say.

**Why, now that the original reason has gone.** The FT test came back negative: an FT-only
generation with a single-branch schema and no `anyOf` still returned nothing (BL-30), so splitting is
*not* the fix for that. What remains is real anyway:

- **The type and the difficulty are paired by the model today, not by the teacher.** The form takes
  counts on two independent axes — three per type, two per level — and nothing says which level
  belongs to which type. Pinning down "2 easy True/False" for the 2026-08-01 matrix took twelve
  separate generations for exactly this reason. One call per type makes the pairing explicit.
- **One broken type costs the whole generation.** Today the pipeline is all-or-nothing.
- **Type-specific instructions reach only their own type.** RV's markability limit has no business in
  the essay prompt (BL-32).

Cost, measured: **+22% of a generation's total bill** (10.5 → 12.8 eurocent on the short text,
`claude-opus-4-8` at $5/M input). Not a factor.

**Part one is built and verified, 2026-08-01: the third outcome exists.**

- `generation_status::PARTIAL` — a seventh status, terminal, for a run that finished and still
  delivered less than was asked for.
- `save_questions_task::store_save_discrepancy()` — M-08's comparison, repeated at the only point
  where the answer is final. The generating stage asks "did Claude return what we ordered?"; this
  asks "did the teacher get what they ordered?", and today the two differed by everything: Claude
  returned six FT questions on every one of nine runs, and all six were dropped afterwards by the
  semantic check, after M-08 had already looked and found nothing wrong. A shortfall sets `partial`;
  a surplus is recorded but leaves the run complete, because the teacher has lost nothing.
- The progress bar reaches 100% in **amber**, not green, and the status page carries a warning
  telling the teacher what is missing and what to do about it.

**Verified on the screen**, by putting the old FE/FT sentence back so the shortfall was
deterministic, running six FT questions, and looking:

> status `partial` · bar `bg-warning` at 100% · *"This generation finished, but produced fewer
> questions than were requested…"* · **Requested: 6 Multiple choice — Received: 0 Multiple choice**

That last line is the one that was missing on all nine failures. The good sentence was put back
afterwards and re-read to confirm it.

**Part two is built and verified: the form can now express what the teacher means.**

Step 1's generation-wide level counts and step 2's per-type counts have become **one grid** — a row
per question type, a column per level, in both levelled modes (free text keeps its single count per
type, having no levels). `matrix_<CODE>_<level>`, 36 fields, hidden by mode.

Why it had to be the grid and not an automatic split: with 3 True/False and 3 single-choice against
levels 2/2/2, the arithmetic divides evenly — and **nobody has still said which True/False question
should be the easy one.** András's point, and it is the right one: a tidy division only hides that
the system is deciding. There was no "clean case" and "remainder case"; there was only the system
deciding, in both.

- `local_artqtml_build_settings()` stores `matrix` and **derives** `counts` and the generation-wide
  level totals from it. Derived, not stored twice, so they cannot drift; and everything downstream
  that reads `counts` — `question_schema::build()`, `build_prompt()`, the save-time discrepancy
  check, every generation saved before today — keeps working untouched.
- **M-16 is gone.** It cross-checked two totals that could disagree; there is one set of numbers now.
- The live total and token estimate in `amd/src/generatesettings.js` read the grid. The step1/step2
  difference indicator went with the two-axis form it compared.

**Verified on the site:** the form renders 36 grid fields and 6 free-text fields with no errors, and
a saved generation of **2 easy True/False + 2 hard single-choice + 1 medium ordering** reads back
exactly that. That request could not be expressed at all this morning.

**One operational note:** `amd/build/generatesettings.min.js` was replaced with the unminified source
so the page works now — Moodle's grunt is not reachable from here. **Run `grunt amd` in the Moodle
root before committing**, and the stale `.map` was deleted rather than left pointing at the wrong
lines.

**Part three is built and verified: one API call per question type.**

`generate_questions_task::call_claude_per_type()` loops over the requested types and calls Claude
once for each, with settings narrowed by `settings_for_type()` — that type's count, and **that
type's row of the grid** as the difficulty levels. A failure is caught per type and the loop
continues, so one broken type no longer costs the whole generation. Only if *every* type fails does
the generation fail as a whole, which keeps the existing retry path meaningful.

**Transport and content failures are now different things**, per András's decision:

| | what it is | what happens |
|---|---|---|
| `transport` | timeout, 5xx, unparseable JSON | retried, as before — this is what absorbed Gemini's three 503s on 2026-07-31 |
| `content` | HTTP 200, valid JSON, nothing usable | **no retry**. The model has answered; asking twice more spends money to be told the same thing. That was FT: three attempts a run, nine runs. |

The distinction is made where it can actually be made — `$parsed === null` separates "the JSON broke"
from "the JSON was fine and empty", and the JSON-fallback loop now breaks on the second.

**Verified on generation 1333**, with diagnostics on so the prompts could be read back. Three
separate `generate` calls in the log, and each carried exactly what the teacher typed into the grid:

```
15:21:17  Generate exactly: 2 x True/False (IH)     Difficulty: Easy: 2, Medium: 0, Hard: 0
15:21:37  Generate exactly: 2 x Single choice (FE)  Difficulty: Easy: 0, Medium: 0, Hard: 2
15:21:45  Generate exactly: 1 x Short answer (RV)   Difficulty: Easy: 0, Medium: 1, Hard: 0
```

5 requested, 5 delivered, completed. The pairing of type and level is no longer the model's guess.

**Part four is built and verified: the progress bar is subdivided.**

The generating loop writes its position into `pendingdata` — `{generating: {done, total, current,
outcomes}}` — **before and after each type**. Nothing reads that column until validating, so it is
free to say where the loop is. The pre-call write is what names the type currently in flight; the
first version only wrote after each call, and on the screen that produced a bar sitting at 25% with
no type name for the whole of the first call, then naming the type that had just *finished*. Caught
by watching it, not by reading it.

`generation_progress::generating_percent()` turns done/total into a percentage between the
generating and validating marks (25% → 45%), `generating_type()` supplies the code, and both
`status.php` (server render) and `classes/external/get_status.php` → `amd/src/status.js` (poll) use
them, so the bar behaves the same whether the page is reloaded or left to poll.

**Verified on generation 1335** (2 easy IH + 2 hard FE + 2 medium SR + 2 easy EH), polled while it ran:

```
17:46:10  25%  Generating questions (Claude) - True/False (25%)
17:46:17  30%  Generating questions (Claude) - Single choice (30%)
17:46:33  35%  Generating questions (Claude) - Ordering (35%)
          ...  Completed (100%) — 8 questions
```

**Part five is built and verified: the button that asks again for the missing types.**

`retrytypes.php`, reached from a button inside the partial notice itself. It does not retry the
partial generation — that one is finished and its questions are real. It creates a **new**
generation on the same source text with the grid narrowed to the shortfall, and stops on the
settings page. András's two decisions, both honoured literally:

- **The teacher presses it**, and presses Generate afterwards. Nothing re-runs by itself.
- **The duplicate check still runs.** Easy to skip, since the text is knowingly the same — but he
  was right: the teacher may come back days later, and being told what already exists for this text
  is the entire point of that screen. `retrytypes.php` compares against *every* generation
  including the source one, where `upload.php` excludes the row being edited.

`missing_types::narrowed_settings()` is where the care went. It zeroes every type that delivered,
and for a type that fell short it reduces the number **only where the type was asked at a single
level** — there the missing questions unambiguously belong to that level. Where the type spanned
several levels it hands back the original row untouched, because which level went missing cannot be
measured: `local_artqtml_questions.difficultylabel` is free text written by the model, not the key
the teacher picked. Guessing it would be the system making the teacher's decision again, which is
the whole reason the grid exists. The settings page is open in front of them either way.

**Two things the screen caught that reading the code did not:**

- **The partial notice printed the shortfall twice** — once in the standalone count-discrepancy
  alert, once inside the notice explaining itself. Two identical amber boxes, stacked.
- **A partly successful run had no way to reach its own questions.** Continue is revealed by
  `amd/src/status.js` on `completed` only, so the notice said "the ones that were produced can be
  used" above a page with no button to use them. Now rendered server-side for `partial`.

And one bug of my own making, found the same way: the first version of the JS reloaded the page
whenever the poll returned `partial` — including on a page that was *already* partial, since
`init()` polls once regardless. Reload, poll, reload. The `data-initialstatus` guard is what stops
it, and the comment in `status.js` says so.

**Verified on generation 1338** (2 medium FT + 1 easy IH, with the FE/FT sentence temporarily
removed from `promptoptioncount` so the shortfall was deterministic — put back afterwards and
re-read character by character): `partial`, 1 of 3 delivered, notice + button, and the follow-up
generation 1339 opened with `matrix_FT_medium = 2` and every other cell empty.

**And one more, found while writing the documentation against the code an hour after I had called
this finished: the follow-up generation could not be started at all.** Its Generate button was grey.
The rule behind it was `Beal-009` — the button is only active when the total equals the previous
generation's total for the same source text — which was the enforcement arm of the X/Y difference
indicator (`Beal-008`). The indicator was removed with the grid this afternoon; the rule outlived it
by an afternoon, invisible, and the first thing it blocked was a follow-up that asks for less **on
purpose**. Removed with András's decision: `enabled = total > 0`, and the `$previous` lookup in
`generate.php` went with it. **Beal-008 comes out of the specification and Beal-009 is rewritten**
to say only that — an earlier draft of this paragraph said both were removed, which the shipped v41
does not do: `Beal-009` is still there, at line 314, reading *"A gomb akkor aktív, ha az összes
kérdésszám nagyobb mint 0"*. The other withdrawal in the same round was `Beal-007`, which merged
into `Beal-005` when the two summaries became one.

Worth saying plainly, because it is the same lesson as this morning's M-16 leftover: I verified that
the follow-up's settings page *opened with the right numbers* and called the feature done. I had not
tried to *press the button*. Verified now, end to end: 1338 partial → button → duplicate panel →
1339 with `matrix_FT_medium = 2` → Generate → **completed, 2 questions**.

**A third leftover from the same removal, found in the same pass: the free-text "Total number of
questions" field.** The specification stated its purpose outright — free text has no levels to add
up, and the X/Y indicator needed a left-hand number from somewhere. With the indicator gone nothing
read it: the prompt takes the free-text *description*, never the count, and the real numbers are the
`count_<CODE>` fields. A teacher could type a number into it and nothing anywhere would change.
Removed — field, settings key, both lang strings — and free-text mode re-checked on the screen:
description plus six per-type counts, nothing orphaned. **Beal-004 rewritten accordingly.**

Three leftovers from one removal is the pattern worth keeping: **when a UI element goes, the things
that fed it and the things it gated do not go with it automatically.** Removing the indicator was
one edit; the enforcement rule, the input field and the test cases were three more, and each was
found by something other than reading the diff.

**Still to build:** nothing on BL-35 itself. Two notes for whoever picks it up next:

- The "Continue anyway" button on the duplicate panel could not be submitted by a scripted click in
  the browser session used for testing (the same is true of `upload.php`'s identical panel, which
  has been in production use all along) — the POST it performs was exercised directly instead, and
  is what created generations 1336 and 1339. Worth one manual click by a human.
- A generation created **before** the grid existed has no `matrix` in its settings, so its follow-up
  opens with the counts filled in and the grid empty. That is the documented pre-grid behaviour
  (`generate.php`'s set_data comment), not a fault of this path, and it is what generation 1336
  showed.

The caveat that shaped the retry half, kept because it is why the retry loop was not simply deleted:
the three attempts are also what absorbs a transient HTTP 503, which happened twice on 2026-07-31
when Gemini was under load. A **transport failure** (timeout, 5xx) is worth the cheap retry; a
**content failure** (valid response, no usable questions) — the FT case — is worth none.

### BL-34 — A diagnostics mode that stores what a run actually sent and got back — resolved 2026-08-01

**András's suggestion, 2026-08-01, and the FT investigation is the case for it.** Eight consecutive
FT generations returned nothing. The schema was the obvious suspect and has been ruled out (BL-30).
What is left cannot be told apart from the interface — did the model return nothing, return questions
typed `FE`, or return something the importer dropped? — and the answer is in a request/response pair
that is not kept anywhere a query can reach.

**What exists today and why it is not enough.** `local_artqtml/debugmode` writes to a file under
`dataroot`. That is fine for someone sitting at the machine, and useless for everything else: a
report cannot read it, it is not per generation, and on this project it meant a whole afternoon of
scraping approval pages to answer a question one `SELECT` should have answered.

**The shape suggested, and it is the right one:** store the diagnostic material in the database,
behind a switch that is off in normal operation. `local_artqtml_log` already exists, is
generation-scoped, already carries `tokensinput`/`tokensoutput`/`httpstatus`/`jsonattempt`, and is
already deleted with its generation — so the row shape is largely there. What is missing is the
payload: the assembled system prompt, the response schema, and the raw response body.

**Three things to decide before writing it, because they are what makes this either useful or a
liability:**

1. **What is stored.** The source text is already in the generations table, so the log need not
   repeat it. The system prompt and the schema are reconstructible from settings; the **raw response
   body is not**, and it is the one thing every open question needs.
2. **Retention.** A stored response body is teacher-authored source text plus model output. It is
   already covered by the privacy provider for `local_artqtml_log`, but a size cap and an age
   cutoff want deciding rather than discovering.
3. **Where the switch lives.** Site-wide alongside `debugmode` is simplest. Per generation would be
   better for support ("turn it on and run it again") but adds a column and a form field.

**What it would have saved today, concretely:** BL-30's cause, BL-31's yield question, and the
"is the count discrepancy recorded?" question, all of which were answered by inference or not at all.

**Already built on the way there:** `tools/reports/generation_outcome.sql`, a Configurable Reports
query giving requested versus delivered questions and the validator's verdict spread per generation.
That is the half of the problem the database *can* already answer, and it replaced the scraping.
See also BL-08 - closed 2026-08-03 with the decision that reports are **not** shipped with the
plugin, so this query stays working material in `tools/reports/`, which the package excludes.

### BL-33 — One prompt for all question types, or one call per type? — decided 2026-08-01: one call per type

**Raised by András 2026-08-01:** the generator prompt is getting complicated, and different question
types may not belong in the same request — the same question then applies to validation.

**One thing was wrong regardless of how this is decided, and is now fixed.**
`question_schema::build()` put **all six** type fragments into the response schema's `anyOf` on every
request, whatever was asked for. A pure True/False generation carried the essay, ordering,
short-answer and both multichoice schemas as well. The counts were sitting in `$settings['counts']`
the whole time and were simply not consulted. As of 2026-08-01 the schema carries only the requested
types, and a single-type generation gets that branch directly with no `anyOf` at all.

That matters beyond the wasted tokens. **FE and FT come out of the same method and differ in exactly
one place, the `type` const** — two near-identical branches to choose between. FT returned zero
questions on six consecutive attempts (BL-30) while every other type returned six every time.
Narrowing the schema removes the choice wherever only one of the pair was asked for, so **this may
close BL-30 on its own**. Not yet measured — an FT-only generation is the test.

**The real trade-off, and the number that decides it.** Splitting buys: an unambiguous schema; an
exact pairing of type and difficulty (today the form takes counts on two independent axes and the
model does the pairing, which is why the 2026-08-01 matrix needed twelve separate generations to
pin down "2 easy True/False"); failure isolation, so one broken type does not cost the whole
generation (BL-26); and type-specific instructions that only reach the type they concern — RV's
markability limit has no business in the essay prompt.

It costs re-sending the source text with every call. Everything else in a request is small next to
it. So the decision turns on the source text's share of a request, which nobody had measured —
hence `tools/prompt_size.php`, which reports it for a real generation and projects the split:

```
php local/artqtml/tools/prompt_size.php --generationid=<id>
```

It measures through the plugin's own `build_prompt()` and `question_schema::build()` rather than a
copy of them, because a copy would measure the copy.

**Worth noting:** validation already pays this cost. `validate_questions_task::build_batches()` puts
the full source text into every batch, so the per-call source text overhead is not new there — only
on the generating side.

### BL-31 — Ordering (SR) invents a filler item when the source cannot supply the required count — resolved

**Resolved 2026-08-01 by moving the catch, not by preventing the cause.** The generator keeps the
quota and keeps its yield; the validator names the invented item and the teacher fixes it. Verified
on two fresh generations, and the record below is kept because the fix that looked obvious was tried
first and was worse.

| | delivered | filler produced | flagged by the validator |
|---|---|---|---|
| quota + no validator check (before) | 36/36 | 4 runs of 6 | never |
| ceiling wording | 14/48 | none | — |
| **quota + Val-033 (now)** | **12/12** | yes | **every one** |

What the validator wrote, unprompted by any example — it names the offending item and quotes the
source:

- *"A 4. elem – »(a sötét szín vége)« – egy helykitöltő"* → `Other`
- *"A »Piros« elem külön nem szerepel a szövegben"* — the text says "a zöldtől a sárgán át a
  mélypirosig" → `Factual error`
- *"Az »Édes« és »Enyhén fanyar« fokozatok nem szerepelnek"* — the text names only the two ends of
  the range → `Factual error`
- *"minden elemnek szerepelnie kell a forrásszövegben, mert nincsenek benne rontó opciók
  (disztraktorok)"* — the reasoning behind the rule, returned in its own words

**And Val-031 fired for the first time in the field**, on the same run: *"A kérdés nehézségi szintje
hibásan 'Apply', miközben nem egy új, a szöveg által nem leírt konkrét szituációt kell megoldani."*
Across the 181 baseline questions the validator raised the level exactly zero times.

**What is left, and it belongs to BL-30:** a short delivery is still not reported to the teacher.
That is the count-discrepancy gap, not this item.

### BL-31 (history) — the measurement that led here

**Measured 2026-08-01, six times across three generation runs.** Every SR question built on the
source text's colour sentence — which names **three** colours, "a zöldtől a sárgán át a
mélypirosig" — came back with a fourth item that is not a colour:

| run | the fourth item |
|---|---|
| 1 | `(sötét irány)` |
| 2 | `(a színskála vége)` |
| 2 | `(a szín a zöldtől a mélypirosig változik)` |
| 3 | `(nincs több szín)` |
| 3 | `(a legsötétebb árnyalat)` |
| 3 | `(a kész, fogyasztható termék)` — same shape, on the pálinka question, where the text gives three steps |

Six different wordings, one behaviour: **the model was told to produce exactly four items, could
find three, and filled the gap rather than returning three.**

**Where it comes from, read out of the code rather than guessed:**

- `db/prompt_defaults.php:89` ships the fragment *"For SR questions, provide exactly
  `{{SR_ITEM_COUNT}}` items to put in order."* — verified unchanged on the running site.
- `generate_questions_task.php:380-388` fills that placeholder from the per-generation override, or
  from `local_artqtml/sritemcount`, or from `DEFAULT_SR_ITEM_COUNT = 4`. The site's admin value is
  **4**.
- Nothing downstream can catch it: `question_schema.php` states in its own docblock that Structured
  Outputs only accepts `minItems`/`maxItems` of 0 or 1, which is *why* counts travel in the prompt at
  all. So the item count is a request, and its correctness is nobody's job afterwards.

**What it costs the student.** The question has no correct answer. Four items must be placed, one of
them is a parenthetical note about the list itself, and qtype_ordering will still grade the attempt.

**The validator caught all six — and disagreed with itself.** Runs 1 and 2 marked them *Needs review*
(90–95%); run 3 marked the same defect *Rejected* (95%). Identical structural fault, two different
verdicts, so a teacher cannot learn from the badge what it means.

**The decision this needs, and it is not obvious.** Admin-036 makes the item count a fixed setting,
and that is what "exactly" implements. The fix that removes the defect — wording the fragment as an
upper bound ("at most N items, and never an item that is not in the source text") — turns the
teacher's setting from a quota into a ceiling. That is a change to what Admin-036 promises, not just
to a sentence, so it wants deciding before it is written. The alternative, keeping "exactly" and
rejecting short questions at import, throws away a question that was already paid for (see BL-26 for
why that matters).

**Worth checking at the same time:** whether FE/FT's `promptoptioncount` has the same shape. It uses
a min/max pair (2–5 on this site) rather than an exact figure, so it probably does not — but it was
not measured, because FT never produced a question (BL-30).

---

**Decided and changed 2026-08-01: the count is a ceiling.** `promptitemcount` now reads *"provide at
most `{{SR_ITEM_COUNT}}` items to put in order. Use fewer if the source text does not support that
many. Never invent an item that is not in the source text, and never add a placeholder, a label or a
note about the list as if it were an item."* Changed in `db/prompt_defaults.php` (the shipped seed)
and in the running site's setting. Specification **v37** rewrites Admin-036 accordingly; the user
manual is listed under BL-25 for the release pass.

**Verified on the site, on generation 1295, not reasoned about.** Four SR questions, **no filler
element in any of them**, all four *Accepted*. The colour question — the one that produced the defect
six times — did not appear at all this time; the model wrote about the tree's life cycle, its origin,
the pectin chain and the product list instead, all of which the text supports with four real items.

**Two things this run also showed, and neither is fixed:**

1. **It delivered four questions, not the six requested**, split 2 Easy / 1 Medium / 1 Hard instead of
   2/2/2 — and `[data-region="countdiscrepancy"]` was still `d-none`, so nothing told the teacher.
   That is BL-30's second point again, now on a type that works: the shortfall is only measured when
   there is something to measure it against. Whether the ceiling wording makes short deliveries more
   likely is **not established** — one run is not a measurement.
2. **The attempt before it (1294) returned zero questions**, on the same settings. SR had produced 6
   of 6 on all six earlier runs today, so a zero appearing straight after this change is worth
   holding open rather than explaining away. Two attempts, one zero, is not enough to tell a prompt
   regression from the same unexplained failure BL-30 describes. **Run SR a few more times before
   this is called done.**

**The migration is written and ran. Version `2026080101`, upgrade step in `db/upgrade.php`.** It
replaces `promptitemcount` **only when the stored value matches the previous shipped string
byte-for-byte**, backs the old value up first (`setting_backup::backup`, Glob-037/038) and leaves an
administrator's own wording alone. The rule the header of `db/upgrade.php` already lays down for any
step that touches an admin-editable setting, applied as written. The upgrade ran on the local site —
*artqtml Success (0.11 seconds)* — and its **negative case is verified**: the site's fragment had
been hand-edited by then, so the step correctly did not touch it.

---

### BL-31 (history, continued) — The wording is NOT settled, and the first attempt was a regression

**The four-sentence version fixed the filler and broke the count.** Eight SR runs after the change,
six questions requested each time:

| | before the change | after (four-sentence version) |
|---|---|---|
| questions delivered | 6, 6, 6, 6, 6, 6 | **0, 4, 4, 3, 0, 0, 3, 0** |
| runs with a filler item | 4 of 6 | **0 of 8** |

So the anti-filler half works — all ten delivered questions carry four real items, and the colour
question that caused the defect six times **stopped appearing at all**; the model now writes about
topics the text can supply four items for. But three runs in eight produced nothing, and none
produced six. Fourteen questions arrived out of forty-eight requested, and
`[data-region="countdiscrepancy"]` stayed hidden every time, so nothing told the teacher.

**Shortening it made it worse, so length is not the variable.** A one-sentence form — *"provide at
most `{{SR_ITEM_COUNT}}` items to put in order, using only items the source text actually
supports"* — was measured over four runs and delivered **1, 0, 2, 1** questions against six
requested. Eighteen SR runs in total now:

| wording | runs | delivered / requested | runs with a filler item |
|---|---|---|---|
| `exactly {{SR_ITEM_COUNT}} items` (original) | 6 | **36 / 36** | 4 of 6 |
| four-sentence ceiling | 8 | **14 / 48** | 0 of 8 |
| one-sentence ceiling | 4 | **4 / 24** | 0 of 4 |

**What the numbers say, and it is not what the fix assumed.** Both ceiling wordings remove the
filler completely and both collapse the yield, and the shorter one collapses it further. The
variable is not how many words the instruction has — it is what it asks. Told to use *only what the
source supports*, the model treats that as a bar most candidate questions fail, and declines rather
than writes. Told to produce *exactly N*, it always writes N and pads when it must.

**The next candidate follows from the successful runs rather than from a new guess.** Every question
the ceiling wordings did deliver had four real items, and every one of them avoided the colour
sentence — the model already knows how to pick a topic that supplies four. So the constraint belongs
on **topic choice**, not on item count:

> *"For SR questions, provide exactly `{{SR_ITEM_COUNT}}` items to put in order. Choose only topics
> for which the source text supplies that many distinct, orderable items — never pad the list with a
> placeholder, a label, or a note about the list itself."*

This keeps the quota (so Admin-036 keeps its meaning and the yield should return) and moves the
"do not invent" rule to where the model was already applying it successfully. **Unmeasured.**

**A fourth reading, and it may be the right one: the test text is too thin.** András asked what a
longer source would do, and it is a fair question — `teszt_forrasszoveg_almafa.txt` is 2565
characters over three paragraphs, and its colour sentence names exactly three colours, which is the
single sentence that produced six of the six defects. On a source roughly twice that length the
one-sentence ceiling wording returned **4, 2, 4, 4** questions against six, against **1, 0, 2, 1** on
the short text. Same wording, same settings, only the source changed. So a good part of what looked
like a prompt regression is the ceiling wording binding on a text that genuinely cannot supply four
orderable items for six different topics.

That reframes the defect rather than excusing it: a teacher will often upload a thin text, and on a
thin text **producing three good questions instead of six padded ones is the right answer**. What is
missing is not the questions — it is that nobody is told. Which is BL-30's second point again.

What is left is a decision, not more runs: whether the count is a quota or a ceiling, and whether a
short delivery has to be reported to the teacher before either can ship. Until then the shipped
`promptitemcount` should be treated as unsettled — see the comment on it in `db/prompt_defaults.php`.

**A correction to an earlier reading in this item.** While the four one-sentence runs were queued
they sat in *Kérdések generálása* for around fifteen minutes, and this entry said a held claim token
was the likely cause. **It was not.** `tools/generation_state.php --stuck` shows every one of them
`completed` with no `processingtoken`, and nothing stuck in the last fifteen generations. The task
runs every five minutes and processes the queue one generation at a time; the wait was the schedule
and the queue depth, not a lock. The inference came from a task list showing "due" next to a cron run
that had executed other tasks — which is what a five-minute schedule looks like from outside.

**What the episode did leave behind is worth keeping:** `tools/generation_state.php`, a read-only CLI
that prints each generation's status **and** whether it carries a claim token, because no page in the
interface shows either. That ambiguity is real even though this particular alarm was false — the two
columns the scheduler selects on are both invisible to the person watching the screen.

### BL-30 — Multiple-choice (FT) produces nothing, and the run still reports success — solved

**Solved 2026-08-01, after nine consecutive FT generations returned zero questions.** The model was
never the problem, and neither was the schema. The diagnostics run (BL-34) showed Claude returning
six FT questions, HTTP 200, and `tools/reports/rejected_questions.sql` showed what happened next —
six rows per run, all identical:

> `multichoiceset (FT): expected at least 2 correct options, got 1`

**The cause is one missing sentence.** `question_semantic_validator` requires an FT question to have
at least two correct options, because FT *is* the multiple-response type — that check is right. But
`promptoptioncount` only ever said how many options to provide, never how many should be correct.
The model therefore wrote FE-shaped questions when asked for FT, one correct option each, and every
one was thrown away at M-07, silently, on the way to the database.

**The schema could not have carried the rule.** FE and FT share the same `options` array of
`{text, correct}`, and JSON Schema cannot say "at least two of these booleans are true". The prompt
is the only place it can be said, which is why the fix lives there:

> *"An FE question has exactly one correct option. An FT question is a multiple-response question and
> must have at least two correct options — if the material does not support a question with two or
> more correct answers, write a different question that does."*

**Verified: 6 / 6 on the first run after the change** (generation 1329), against nine consecutive
zeros before it.

**Three things this leaves behind, and they are not fixed by the sentence:**

1. **The generation still reported success while delivering nothing** — nine times. That is the
   missing "partly successful" outcome, and it belongs to BL-35.
2. **No count discrepancy was ever recorded.** Six requested, zero delivered, and
   `[data-region="countdiscrepancy"]` stayed hidden on every run. M-08 computes the discrepancy
   inside the generating stage, from what Claude returned — and Claude returned six. The questions
   are lost later, in saving, where nothing compares anything. **The check is in the wrong stage.**
3. **`question_rejected` was logged from the very first failure and nobody could see it.** The rows
   existed in `local_artqtml_log` since that morning; what was missing was any way to look at
   them. The diagnostics mode was worth building, but this particular answer only needed a report
   over data the plugin was already writing.

### BL-30 (original report) — Multiple-choice (FT) produces nothing, and the run still reports success

**Measured 2026-08-01, three times, on three separate generations.** A generation asking for six FT
(multiple-answer multichoice) questions finished with **zero questions** on every attempt:

| generation | mode | requested | delivered |
|---|---|---|---|
| 1260 | Easy/Medium/Hard | 6 FT | 0 |
| 1266 | Bloom | 6 FT | 0 |
| 1270 | Easy/Medium/Hard (fresh generation, same settings) | 6 FT | 0 |

**The same settings on the other five types worked on the first attempt** — IH, FE, SR, EH and RV
each returned exactly 6, split 2/2/2 across the levels, in both difficulty modes. So this is not the
API being unavailable and not the matrix settings; FT is the variable.

**Three things the run does not do, and each is its own problem:**

1. **It reports success.** `index.php` shows *Completed*, `status.php` draws the bar at 100%, and the
   banner reads *"Generation completed successfully — 0 question(s) generated."* A pipeline that
   delivered nothing should not be green.
2. **No count discrepancy is recorded.** `local_artqtml_generations.countdiscrepancy` is empty
   (`[data-region="countdiscrepancy"]` still carries `d-none`), even though 6 were requested and 0
   arrived. M-08 exists for exactly this and did not fire — most likely because the discrepancy is
   only computed over questions that were imported, so "none at all" falls through the check.
3. **Retry does nothing, and the reason is now read out of the code rather than guessed.**
   `status.php:87-106` runs its rollback-and-requeue **only** inside `if ($generation->status ===
   generation_status::FAILED)`; every other status falls straight through to the redirect. These
   generations are `completed`, so the link is a no-op that looks like an action. That makes the two
   defects compound: the run is wrongly green (point 1), and being green is exactly what closes the
   recovery path.

**The most likely explanation was tested on 2026-08-01 and is wrong.** FE and FT come out of the same
schema method and differ only in the `type` const, so two near-identical branches in the response
schema's `anyOf` were the obvious suspect. `question_schema::build()` now sends only the requested
types, and an FT-only generation gets that single branch with **no `anyOf` at all**. Two fresh
FT-only generations were run against it, one per difficulty mode:

| generation | mode | schema sent | delivered |
|---|---|---|---|
| 1324 | Easy/Medium/Hard | FT branch only, no `anyOf` | **0 / 6** |
| 1325 | Bloom | FT branch only, no `anyOf` | **0 / 6** |

**Eight consecutive FT failures now, and the schema is exonerated.** The narrowing is worth keeping
on its own merits — it saves roughly 1,320 input tokens on every single-type call — but it does not
touch this defect. Whatever stops FT is upstream of the schema or downstream in the importer.

**Not diagnosed, and it needs the log to be.** Whether the model returned nothing, returned questions
typed `FE` instead of `FT`, or returned something the importer rejected cannot be told from the
interface. The plugin's debug log is written into `dataroot` inside the container, so this needs a run
with debug mode on and the file read on the machine. Worth knowing before guessing: FE and FT share
`fe_ft_schema()` and differ only in the `type` const, and FT is the only type with a
`partiallycorrect` branch (`feedback_ft_partial`, Admin-022) and the only one whose import path sets
`$single = false`.

**Not a regression from yesterday's prompt work, as far as it was checked:** the *Lifecycle FT*
generations from 2026-07-27 each completed with 1 question. What changed between then and now was not
established.

### BL-27 — The validation justification follows the site's language, not the teacher's — resolved
Raised and decided on 2026-07-31, within the hour. The justification now follows the **source
text's** language (Val-030), which was a third option neither the item nor the original D-2 decision
had considered.

**What was seen.** With the admin user switched to Hungarian: Hungarian interface, Hungarian
questions, English justification, on one screen. Nothing was broken - D-2 said the justification
follows the *site's* language, only the user's preference had changed, and validation runs in a
scheduled task where there is no logged-in user and Moodle uses the site default. It still read as a
fault, and the setting a user would go looking for was already the one they wanted.

**Why the source text is the right answer, and not the third-best.** The justification is about the
question; the question is in the source text's language (Gen-031). Tying the two together means a
teacher never sees a question and its reasoning in two different languages, whatever the site or
their account is set to.

**And it removes the whole class of problem rather than moving it.** The clause is appended from a
lang string, so which language pack is active decided what it said. Now both packs say the same
thing - write it in the source text's language - so it no longer matters which one a background task
picks up. The question "whose language?" has stopped having a wrong answer.

D-2's original reasoning still holds where it applies: the clause is appended by code so an admin
editing the template cannot drop it, and the `suggestion` and `problem_category` values are still
exempt from translation, because asking for a language is exactly the instruction most likely to
make a model localise an enum and break the schema.


Kept rather than deleted, so a closed decision is not reopened later — the failure mode that cost
three re-litigated items on 2026-07-27.

### BL-21 — The dev tooling lived inside Moodle's own composer.json — resolved
Closed 2026-07-31. `~/projektek/moodle/composer.json` and `composer.lock` are **byte-identical to the
pristine `v4.5.12` tag** again; the manifest mentions none of the four tools, and `git status` on the
Moodle tree shows no modified files at all. The toolchain now lives in `.devtools/`, outside
anything Moodle owns.

**What was there:** eleven packages added by hand - `phpstan/phpstan`, `phpmd/phpmd`,
`squizlabs/php_codesniffer`, `moodlehq/moodle-cs` and their dependencies - +11 lines in Moodle's
manifest and +773 in its lock file. Three reasons to move them, all now addressed:

- **A reset would have wiped it silently.** It cannot now: `.devtools/` is not Moodle's, and
  `tools/devtools/composer.json` in this repository is the record that never existed before. Restore
  is a copy and a `composer install`.
- **Two lifecycles were coupled.** Moodle's version is pinned by BL-20; the linters no longer sit in
  that same pinned file, so upgrading one is no longer upgrading the other.
- **Further plugins would have inherited a toolchain from a file none of them own.** `.devtools/` is
  shared and neutral.

**The design was shaped by two questions, and both changed it:**

> *"If a Moodle update comes, how will we remember we do not use the one under Moodle?"* Nothing
> depends on remembering. `tools/check.sh` resolves the toolchain and **prints which one it used**
> on every run, warning and naming this item if it ever falls back to Moodle's `vendor/`.

> *"If a new tool version comes out, do we learn it from a failed CI? Is that not too late?"* It
> was, and it was the actual state - CI pulled PHPStan `latest` on every run. Now `ci.yml` pins
> `PHPSTAN_VERSION: 2.2.5`, `check.sh` **compares the local version against it and fails on a
> mismatch**, and `.github/dependabot.yml` turns a new release into a pull request instead of a red
> build.

**Verified on the machine, not reasoned about:** check.sh reports `.devtools/vendor/bin`, phpcs
0 errors and 0 warnings, phpstan `[OK] No errors`, versions local 2.2.5 against CI 2.2.5.

**One trap worth remembering:** in the container composer runs as root, and root-mode composer
disables plugins automatically - including the one that registers moodle-cs as a phpcs standard. The
install would have reported success while leaving the `moodle` rulebook unavailable, and phpcs would
have failed later on something that looked unrelated. `COMPOSER_ALLOW_SUPERUSER=1` is what makes the
`installed_paths set to ../../moodlehq/moodle-cs` line appear, and that line is the proof.

**Two things left behind on purpose, both in `tools/TOOLCHAIN.md`:**

- The old binaries are still in Moodle's `vendor/bin`. `git checkout` restored the manifest but does
  not clean the directory. `check.sh` prefers the new location so nothing picks them up by accident,
  but a hand-typed `vendor/bin/phpcs` still would. Clearing them means `composer install` against
  the restored manifest, which also reinstalls Moodle's own PHPUnit and Behat - its own pass, not
  this one.
- `phpmd` did not move across. It was installed and called by nothing, not by CI and not by
  check.sh. It returns when it earns a gate.

**A residual for when the second plugin arrives:** `tools/devtools/composer.json` lives in *this*
plugin's repository, so the next plugin would copy its toolchain from here. At that point the
manifest wants a neutral home of its own - the same shape as this item, one level up.

### BL-17 — The licence visual baseline photographs data, not layout — closed, absorbed by BL-22
Closed 2026-07-31. The item is about `tests/visual/license.visual.spec.ts`. **That file no longer
exists** - it went on 2026-07-30 with the rest of visual regression testing (`44c8c2b`, BL-22), so
this item described a defect in a file that had already been deleted.

Nothing is lost by closing it. The lesson was the point, not the file, and BL-22 now carries it in
full among its switch-back conditions: no baseline that photographs environment data, and the `mask`
option as the way to keep such a baseline on layout instead of content.

**The shape is worth noticing, because it is the third one today.** BL-04 asked for the cadence of a
run that never existed; T-06 objected to a baseline that had been deleted; this one names a deleted
file. **A decision that removes something has to sweep the items that referenced it, in the same
pass** - otherwise the list keeps describing a codebase that is no longer there, and every reader
pays to find that out again.

### BL-09 — Narrow the `tools/**` full-suite CI rule — closed, the half that remains moved to BL-23
Closed 2026-07-31, in two halves that ended differently.

**The first half is moot.** The item said `tools/build_docs.py` costs about 16 minutes of CI for no
benefit, because a change to `tools/**` forced the entire browser suite. **Today it costs
nothing:** `grep -rn 'select-specs' .github/` returns nothing at all. The job that consumed the
script went with BL-23 on 2026-07-30, so the rule this item wanted narrowed is not being applied by
anything. `select-specs.sh` is still in the repository, unchanged and unused, waiting with the suite.

**The second half is real and was moved, not dropped.** `tests/migration-backup.spec.ts` is named
nowhere in `select-specs.sh`, so it only ever ran when something forced the whole suite. That is the
opposite failure from the first half - it costs coverage rather than time, and quietly. It is now
BL-23's fifth switch-back condition, together with the guard this item proposed: a test asserting
that every `tests/*.spec.ts` is mapped, so a new spec cannot be added without being selected.

**Kept from the original wording, because it is still right:** the underlying principle - "when in
doubt, run everything" - is correct. What was wanted was the narrowing of one path, not a relaxation
of the rule.

### BL-04 — Revisit the scheduled full test run — closed as moot
Closed 2026-07-31. The item asked for the cadence of a scheduled full test run to be rethought.
**There has never been one.** `git log -S'schedule:'` and `-S'cron:'` against `.github/workflows/ci.yml`
return nothing, in any commit - the workflow has only ever triggered on push, pull_request and
workflow_dispatch.

What did exist was two other things, and neither is what the item named:

- the `run_all_specs` dispatch input - a manual switch, not a cadence. Removed 2026-07-30 with
  BL-23 (`e605a8a`).
- `select-specs.sh`'s automatic `ALL` branch, taken when a shared file changed. The script is still
  in the repository; the job that called it is not.

**Also corrected here:** the 2026-07-30 handover said "BL-23 says what replaces it". BL-23 says when
the suite returns - at v1, as a frozen reference state - and says nothing about its cadence. The
question of what triggers a full run is real, but it is only answerable once the suite runs again,
so it has been written into BL-23's switch-back conditions rather than kept alive here.

### BL-20 — The Moodle version moved under us, in two places — resolved
Raised and decided on 2026-07-30, after `phpunit --testsuite local_artqtml_testsuite` refused to
run: *"Moodle PHPUnit environment was initialised for different version"*. Nothing in the plugin
had changed; the Moodle tree underneath it had.

**Both environments tracked a moving branch, and they moved independently.** Locally,
`~/projektek/moodle` sits on `MOODLE_405_STABLE` at `72261b42`, "weekly release 4.5.12+". On CI,
`ci.yml` set `MOODLE_BRANCH: MOODLE_405_STABLE`, and `moodle-plugin-ci install` re-cloned that
branch on every run - so CI could turn red while nobody touched anything, and a local test failure
did not reliably mean bad code.

**The decision: follow major versions only, and treat each move as its own piece of work.** That
settles the one argument for leaving CI on the branch - it was the early warning that a new Moodle
broke the plugin - because a major-version move is planned work either way, and weekly minor
commits produce noise rather than signal.

`ci.yml` now pins `MOODLE_BRANCH: v4.5.12`, the highest 4.5 tag (`3be6bd6c`; there is no 4.5.13).
One variable covers all three jobs, including the browser job's own clone. Checked rather than
assumed: `InstallCommand` validates the value with `Validate::gitBranch()`, pattern
`/^[a-zA-Z0-9\/\+\._-]+$/`, which a tag satisfies; `MoodleInstaller::install()` and the browser
job both run `git clone --depth 1 --branch <value>`, which git resolves to a tag. **A raw commit
hash would not work** - `git clone --branch` does not accept one - so the local tree's "4.5.12+"
state is not reproducible on CI. `v4.5.12` on both sides is the only way to make them identical.

**One step is not done, because it rewrites a working tree:** the local checkout is still on the
branch, slightly ahead of the tag. `cd ~/projektek/moodle && git fetch --tags && git checkout
v4.5.12` moves it, after which the Docker instance needs its usual upgrade run.

**A second drift source is left alone deliberately:** the browser job cloned moodle-docker with
no branch at all (`git clone --depth 1 https://github.com/moodlehq/moodle-docker.git`), so it
follows that repository's default branch. It is the harness, not the product, and it has not caused
a failure yet - but it is the same shape, and worth remembering the next time CI breaks without a
commit.

`$plugin->requires` is `2024100700` (Moodle 4.5.1) and is a floor, not a pin - it constrains
neither environment.

### Jov-036 — Native question editing needed a hand-made enrolment — resolved
Closed on 2026-07-30, and the backlog's framing of it was wrong. This number belongs to a
specification requirement about *visibility* — "Normál kurzustanár nem látja a draft kérdéseket…
hozzáférés kizárólag a plugin approve.php oldalán keresztül" — and the backlog had reused it for
the consequence: an administrator had to enrol each teacher in the draft course as an
editingteacher before the Edit and Preview links did anything but lead to a permission error.

The plugin now grants access itself. `draft_role` creates a role of its own and assigns it in the
draft course when a generation starts. Three capabilities and no archetype: `moodle/course:view`
(both native pages call `require_login($courseid)`, which for an unenrolled user passes only
through `is_viewing()`), `moodle/question:editall` and `moodle/question:useall`. No view capability
of any kind, so the course's question bank listing stays empty for this role and the drafts remain
reachable only from the approval page — Jov-036's own requirement, kept.

**The "all" pair is deliberate, and the first attempt got it wrong.** `editmine`/`usemine` was
written first, on the reasoning that a user should only touch their own drafts. That contradicts
Glob-031: this is a site-wide tool, any user with `local/artqtml:use` may act on any generation.
The narrow version would have let a reviewer approve a colleague's question but not correct it —
the workflow the plugin exists to support. What keeps the breadth honest is a prompt, not a
smaller capability: the Edit action asks for confirmation, naming the generation's owner, whenever
the question is not the user's own. Preview does not, because it changes nothing.

Written up as **Jov-047** in specification v33, with TC-Jov-106/107/108 in register v40.
`tests/local/draft_role_test.php` covers the role itself: idempotent creation, the exact capability
set, course-context-only assignability, no enrolment, no duplicate assignment.

### BL-18 — The page-width decision is in the code, not in the specification — resolved
Closed on 2026-07-30. Written up as **Glob-041** in specification v33: the five user-facing pages
are at most 1120 px wide, neither the Boost `standard` layout's 830 px nor the full viewport, and
the admin pages are explicitly outside it because they take their own page layout. TC-Glob-069 in
register v40 measures it.

### BL-19 — The date format is in the code, not in the specification — resolved
Closed on 2026-07-30, in the same pass as BL-18. Written up as **Glob-042** in specification v33,
including the exception: the licence page's two dates and the expiry warning keep Moodle's
locale-aware format, because Lic-025 requires the site language's convention there. TC-Glob-070 in
register v40 checks the format on the list and approval pages.

### BL-07 — The CSS editor page has no page title — resolved
Closed on 2026-07-30. The cause was specific, not an oversight in rendering: the page runs through
`admin_externalpage_setup()`, which sets `$PAGE->set_heading($SITE->fullname)`
(`lib/adminlib.php`), so the one `<h1>` the header prints names the *site*. The page never named
itself, and `csseditorheading` — present and translated in both `lang/en` and `lang/hu` — was
written for exactly that heading and never called.

`css_editor.php` now prints it with `$OUTPUT->heading()`, whose default level is 2
(`lib/classes/output/core_renderer.php:2914`).

Second finding, fixed in the same change: the six per-page headings were level **4**, sitting
directly under the site `<h1>`. A 1 → 4 jump is a WCAG failure, named as such in this repository's
own `moodle-accessibility` skill ("Skip levels = WCAG fail"). They are level 3 now, so the page
reads h1 → h2 → h3.

Raising the level alone was wrong on screen, and the first screenshot showed it: Boost sets
`$h3-font-size` to 1.75x and `$h2-font-size` to 2x (`theme/boost/scss/bootstrap/_variables.scss`),
so the section labels came out barely smaller than the page title and the two competed. They now
carry the `h4` class (`$OUTPUT->heading($text, 3, 'h4')`), which keeps the size they always had.
Outline level and type scale are separate concerns.

No visual baseline covers this screen (`tests/visual/` holds admin, approve, generate, license,
list, status, upload), so the heading-level change breaks no snapshot.

### BL-12 — Cell text breaks mid-word in the plugin's tables — resolved
Closed on 2026-07-30. Observed 2026-07-28 on the CI visual baselines: ordinary words split inside
data cells — "True/F alse" in the question type column, "PWT1-IH- 0001" in the name column.

The cause was `overflow-wrap: anywhere` on `.artqtml-table td`, kept so that a column could
shrink below its longest word: without it an unbroken string (a question code, a pasted URL) sets a
floor the table cannot go below, and the table overflows its container at narrow widths — the
regression Glob-034/035 and `tests/responsive.spec.ts` exist to prevent. D-1 had already fixed the
header half.

The item was framed as a per-column decision — naming which columns hold unbroken tokens and which
hold short labels. It did not need one. `overflow-wrap: break-word` gives the protection without
the cost: it still breaks a genuinely oversized single word rather than letting it overflow, but it
does not reduce the element's min-content contribution, so it never breaks a word that would have
fitted on a line of its own. Cells now carry the same rule as headers, and the two declarations are
merged into one block in `styles.css`.

Why the narrow-width guarantee survives: `responsive.spec.ts` measures 1440, 1024 and 768 px, and
below lg/xl the wide columns collapse into the name cell through Boost's display utilities. The
widths where overflow was ever a risk are the widths where few columns remain.

**Not verified locally.** `responsive.spec.ts` is the test that would catch a regression here, and
it needs the Docker Moodle. The visual baselines for the approval and list pages will also differ
— both from the wrapping and from the date format change below — and that is a consequence, not a
regression.

### BL-11 — Changelog — resolved
`CHANGES.md` created on 2026-07-29, newest release first, with the 2026072800 entry written while
the changes were still fresh. The backlog asked for "date, version number, one or two sentences";
this release needed grouped bullets instead, because it carries three days of user-visible change -
the format follows the intent, not the letter.

### BL-16 — `tests/` shipped to customers only because of the integrity manifest — resolved
Closed on 2026-07-29. `tests` joined `tools` and `docs` in the manifest's exclusion list, in both
places that hold it (`license_file_integrity.php` and `tools/generate_license.php` - they must move
together or the manifest and the check disagree), and the package now excludes the directory.

What made it urgent: `tests/fixtures/licenses/*.lic` are signed with the production key and verify
against the embedded public key, and nothing bound a licence to a site - so every package shipped a
working perpetual licence for any Moodle install. The site binding (Lic-029) closes the second half
of that; excluding `tests` closes the first.

Consequence to remember: an install still holding a manifest licence issued before this change needs
a new `.lic`, because its manifest lists test files the package no longer contains.

### BL-14 — The manual did not say the AI instruction reaches the model — resolved
Closed on 2026-07-29 in `docs/felhasznaloi_kezikonyv_v4.md`, together with the two changes it was
waiting to be batched with: the corrected deduction wording (Gen-030) and the licence warning
thresholds with their real defaults (Lic-025..027).

The round also picked up something that was not on the list: the manual's register was mixed. The
chapters rewritten on 2026-07-27 addressed the reader informally, while the upload, status and admin
chapters - and the whole troubleshooting table - still used the formal register. Nine places
unified.

Deliberately left out: Lic-028 (the pre-flight quota check) is specified but not implemented, and a
user manual describes what the product does, not what it will do.

### BL-13 — Field-table admin selectors that named nothing — resolved
Opened and closed on 2026-07-28/29. The field table referenced 28 admin settings by
`#id_s_local_artqtml_<name>`; 21 of those names did not exist in `settings.php`. Walking them one
by one:

- **18 were plain renames** — the table carried older, shorter names (`maxquestions` →
  `maxquestionsperrun`, `draftcourse` → `draftcourseid`, `multipleattempts` → `retrydefault`,
  `claudetokenlimit` → `generatortokenbudget`, and fourteen more). Corrected in
  `docs/mezotabla_v11.md`, every target verified to exist.
- **One row deleted** — ADM-F024 described an admin-level default for the deduction. There is none;
  it is set per generation and per question type.
- **Two became requirements** — LIC-F009 and LIC-F010 looked like they named nothing because the
  two licence thresholds are *not* Moodle admin settings: they live on the licence page's own form
  and are saved with `set_config`. Both rows now carry their real selectors
  (`#id_licenseannualwarningdays`, `#id_licensequestionwarningpct`), and the behaviour behind them
  is specified as Lic-025…027.

**What this did not cover:** 13 ADM rows still carry no selector at all (`[TBD]`) — the connection
test, the model dropdown, the warning banners. That is D-0b, and it remains T-01's blocker.

### BL-05 — Bump the GitHub actions from `@v4` to `@v5` — done
`actions/checkout`, `actions/setup-node` and `actions/upload-artifact` raised to v5; the Node.js 20
warning is gone. (See `docs/nyitott_kerdesek_v7.md`.) BL-05b is the follow-on, still open.

### BL-06 — Review the privacy notice and privacy provider — resolved
The `privacy:metadata` key was thought to be factually false. It was already settled: the plugin
has a full privacy provider. Found on 2026-07-27 to have been closed long before the open-items
list caught up.

## Notes

- Refactor only after the full test suite (browser tests + PHPStan + PHPMD) is in place
- Each refactor should be a separate git branch with full regression testing before merge
