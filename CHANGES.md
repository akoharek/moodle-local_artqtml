# Változásnapló — ArtQTML (`local_artqtml`)

A legfrissebb kiadás áll elöl. A verziószám a `version.php` `$plugin->version` értéke; a
zárójelben álló kiadásszám a `$plugin->release`.

## 2026-08-10 — `2026081011` (1.0.0)

**Security-policy follow-ups (RISK_*, date filter, POST abort/retry, API key harden)**

- `db/access.php`: `local/artqtml:use` and `:configure` now declare Moodle `riskbitmask` values
  (`RISK_SPAM|RISK_XSS|RISK_PERSONAL` / `RISK_CONFIG|RISK_XSS|RISK_DATALOSS|RISK_PERSONAL`).
- Date filter on the generation list: HTML5 `Y-m-d` via `PARAM_TEXT` + strict
  `parse_filter_date()` (no loose `strtotime`); invalid values cleared from the form display.
- Abort / retry (status) and abort-delete (generate) are POST + sesskey only — no state change
  via GET+sesskey URLs; retry-missing-types button likewise.
- API key decrypt failure no longer falls back to treating ciphertext as plaintext; runtime and
  admin UI treat the key as unset until re-saved (debugging notice for admins).

## 2026-08-10 — toolchain

**Semgrep CI + PHPStan disallowed-calls (finding #11)**

- Új Semgrep workflow (`p/php`) — szabad SAST a privát repohoz (nem SonarCloud).
- PHPStan a `tools/devtools` composer-es binárisára vált (phar helyett), és betölti a
  `spaze/phpstan-disallowed-calls` dangerous / execution / insecure szabályait.
- A `sha1()` a duplicate detectorban szándékos (nem kripto hash); `mt_rand()` → `random_int()`.

## 2026-08-10 — `2026081010` (1.0.0)

**Egyedi CSS szerkesztő eltávolítva (finding #10)**

- A per-page custom CSS editor (`css_editor.php`, `custom_css`) kikerült a kódból.
- A megjelenés a Moodle témára marad; az upgrade törli a `css_*` config sorokat.
- `styles.css` és a Glob-001 témaviselkedés megmarad.

## 2026-08-10 — `2026081007` (1.0.0)

**Finding #5: `security_filter` újrafuttatása a generálás / validálás előtt (defense-in-depth)**

- A Claude-hívás előtt (ütemezett `generate_questions_task`) és a Gemini-hívás előtt
  (`validate_questions_task`, DB-ből újraolvasott forrásszöveg) újra fut a `security_filter`.
- Találat esetén: nincs AI-hívás; a generálás `started` / Megkezdett állapotra áll vissza
  (draft / pending takarítás, mint Abort); a tanár újra megnyithatja a szövegfeltöltést.
- Felhasználói üzenet (EN+HU): „Unexpected error occurred. Please restart the generation.” /
  „Váratlan hiba történt. Indítsd újra a generálást.” — szűrő-belsőségek nélkül; admin napló:
  `security_filter_blocked`.
- `generate.php` Start előtt is ellenőriz (mint a forrásszöveg-hossz), feltöltésre irányít.
- PHPUnit: mérgezett forrás → nincs Claude/Gemini, rollback `started`-re.

## 2026-08-10 — `2026081006` (1.0.0)

**PHP debug fájlnapló: rögzített dataroot útvonal (finding #4)**

- A szabadon megadható `debugfilepath` admin beállítás megszűnt (régi config figyelmen kívül
  hagyva / upgrade törli) — tetszőleges fájlrendszer-útvonal nem elfogadható.
- Debug mód bekapcsolva a PHP debug napló mindig ide íródik:
  `$CFG->dataroot/local_artqtml/debug.log` (könyvtár létrehozása szükség szerint).
- Minden sor `[local_artqtml]` előtaggal azonosítható; a beállításokban a feloldott útvonal
  csak olvasható súgóként látszik.
- Ez a **PHP debug fájlnapló**; az API-forgalom / diagnosztika továbbra is az adatbázisban
  van (`local_artqtml_log`), változatlanul.
- PHPUnit: útvonal-feloldás + legacy config figyelmen kívül hagyása.

## 2026-08-10 — `2026081005` (1.0.0)

**Képességek szétválasztása + törlés csak saját + `:use`**

- `local/artqtml:use` → tanári használat (lista, generálás, jóváhagyás, státusz, saját törlés).
  Nem ad admin/beállítás hozzáférést.
- `local/artqtml:configure` → csak admin beállítások (settings, license, model action,
  test_connection). Nem ad generálási UI-t és nem törölhet.
- Mindkettő (pl. manager) → mindkét terület; egyik sem helyettesíti a másikat.
- Generálás törlése: csak `:use` + tulajdonos (`delete.php`, lista, „Törlés és kilépés”).
- A settings fa `:configure` birtokosoknak is regisztrálódik (nem csak `hassiteconfig`).
- PHPUnit: capability separation audit + delete policy (owner / non-owner / configure-only /
  configure+use non-owner).
- Glob-031 komment az AuthZ checkpointokon: a kollaboratív `:use` szándékos; törlés csak
  tulajdonosnál (`delete.php`).

## 2026-08-10 — `2026081004` (1.0.0)

**A kérdésszövegben nem jelenhet meg „szöveg szerint” / „according to the text”**

- A generátor prompt mindig megkapja: ne írjon forrás-meta hivatkozást a kérdésbe vagy a
  válaszlehetőségekbe (HU/EN: *szöveg szerint*, *a szöveg alapján*, *according to the text*,
  *based on the passage* stb.).
- Ha a modell mégis így kezd: a tisztító levágja a vezető mondatrészt; ha bent marad, a
  szemantikai validátor elutasítja (stem, opciók, SR elemek).
- A validáló AI wording-utasítása ugyanezt a hibát `needs_review`-ként jelzi.

## 2026-08-10 — `2026081003` (1.0.0)

**Rövidnév súgó: amit a mező tényleg elfogad**

- A súgó / tooltip és a formátumhiba szövege most a tényleges ellenőrzést mondja: legfeljebb 8
  ASCII betű vagy szám (`a-z`, `A-Z`, `0-9`; ékezet, szóköz, írásjel nélkül), mentéskor
  nagybetűssé alakítva — nem a Moodle kurzus-rövidnév szabályait.
- A PHPUnit a kisbetűs érvényes, az ékezetes elutasított, és a súgó nagybetűs mentés
  megemlítését is lefedi.

## 2026-08-10 — `2026081002` (1.0.0)

**Részleges generálás: miért hiányzik a kérdés (a panelen)**

- A „Kért / Kapott” sáv eddig csak a darabszámot mutatta. A részleges (partial) státuszpanel
  most a már meglévő `local_artqtml_log` eseményekből (`type_generation_failed`,
  `question_rejected`, Claude undershoot) rövid, felhasználói indokot is kirak — új API-hívás
  nélkül.
- A szemantikai elutasítás nyers angol technikai szövege továbbra is csak a naplóban marad;
  a panel kategorizált nyelvű sort mutat.

## 2026-08-10 — `2026081001` (1.0.0)

**Jóváhagyó oldal: vissza a generálások listájára**

- Az `approve.php` fejlécében (a generálás neve alatt) megjelenik a **Vissza a listához** gomb —
  a plugin listaoldalára (`/local/artqtml/index.php`) vezet, sesskey nélkül (egyszerű GET).

## 2026-08-10 — `2026081000` (1.0.0)

**Kérdésbeállítások: a nehézségi mód váltásakor csak a aktív oszlop-hint látszik**

- A 2. lépés „Könnyű / Közepes / Nehéz” és „Emlékezés / Megértés / Alkalmazás” oszlopfeliratai
  eddig nyers HTML-ként kerültek a formba, ezért a `hideIf` nem rejtette el őket módváltáskor —
  a nem aktív mód szövege bent ragadt.
- Mostantól `static` elemek, a nehézségi módhoz kötve; egyetlen élő **Összesen** marad a 2.
  lépésben (a lépések közötti és a módok szerinti másolatok kikerültek).

## 2026-08-07 — `2026080703` (1.0.0)

**Frankenstyle átnevezés: `local_aiquizgen` → `local_artqtml`**

- A plugin könyvtára, komponensneve, DB táblái (`local_artqtml_*`), képességei, webservice-jei,
  ütemezett feladatai, AMD moduljai és nyelvi fájljai **artqtml** / **ArtQTML** néven mennek tovább —
  az `aiquizgen` név máshol is foglalt volt.
- Meglévő telepítéseken az `install` / `upgrade` (és `cli/migrate_from_aiquizgen.php`) átnevezi a
  régi táblákat és átírja a Moodle registry sorokat; a fájlintegritásos `.lic` fájlokat újra kell
  kiállítani.

## 2026-08-07 — `2026080702` (1.0.0)

**CI: PHPStan / PHPCS a Felt-036 index-korlát körül**

- Az `object_index::build()` opcionális byte-plafont fogad (tesztekhez), így nincs `new static()` /
  `@phpstan-consistent-constructor` (amit a Moodle PHPCS elutasít). A Felt-036 korlát-teszt továbbra
  is fut.

**A licensz sárga figyelmeztetése a listaoldalon is látszik (Lic-025 / Lic-026)**

- Ha a licensz hamarosan lejár, vagy a kérdésszám-kvóta a figyelmeztetési küszöböt eléri, a
  **sárga sáv a generálások listaoldalán is megjelenik** — nem csak az admin füleken, és nem csak
  akkor, amikor a rendszer már blokkol. A tanár így időben értesül.

**A licensz dátumai is a megszokott formátumot követik (Glob-042)**

- A licensz fülön és a lejárati figyelmeztetésben a dátum **ÉÉÉÉ.HH.NN óó:pp** (24 órás), ugyanaz,
  mint a lista és a többi felület — nincs külön locale rövid dátum.

**A PDF memória-korlát szigorúbb (Felt-036)**

- Az `object_index` mostantól **byte-korláttal** tartja az indexelt objektumtesteket (128 MiB), az
  ObjStm kicsomagolásnak összesített plafonja van, és a szülő ObjStm törlődik, miután a gyerekek
  bekerültek. A sikeres oldalas kinyerés után a nyers fájltartalom eldobódik. Cél: a
  „adatfolyamok egyesével” garancia közelebb legyen ahhoz, amit egy enterprise pen-test vár.
- **ObjStm gyerekobjektumok indexelése javítva:** a párok olvasása eddig az objektumszámot
  offsetként használta; a gyerekek most a helyes (szám, eltolás) párral kerülnek be.

## 2026-08-06 — `2026080609` (1.0.0)

**A Word és a LibreOffice PDF-jéből ismét megjön a szöveg (BL-59)**

- **Egy Wordből vagy LibreOffice-ból mentett PDF eddig azt az üzenetet kapta, hogy nem tartalmaz
  szöveget** — pedig tartalmazott. Ez a termék fő bemeneti útja: a tanár leggyakrabban PDF-et hoz.
  Mostantól a szöveg megjelenik a Forrásszöveg mezőben, ékezetekkel, szerkeszthetően.
- Négy fájlon mérve: a LibreOffice-exportból **0 → 21 568** karakter, a másik generált PDF-ből
  **0 → 21 589**. Egy 21 oldalas, PowerPointból exportált tananyagból **64 → 16 119** karakter — ez
  az a fájl, amiről a hiba két nappal korábban kiderült. Egy korábban is működő Word-export
  változatlanul **4 768** karaktert ad, tehát ami eddig ment, az továbbra is megy.
- **Ami továbbra sem megy:** a szkennelt, csak képet tartalmazó PDF (nincs szövegfelismerés), és egy
  régebbi tömörítési eljárással készült PDF.

**Az elutasított fájl nem törli ki a tanár beírt szövegét (BL-53)**

- Ha a tanár beírt egy szöveget, feltölt egy fájlt, a rendszer rákérdez, ő igent mond, **és a fájlt
  a rendszer utána visszautasítja** — a beírt szöveg eddig eltűnt. Ráadásul a hibaüzenet épp azt
  tanácsolta, hogy illessze be a szövegét abba a mezőbe, amit az imént kiürített. Mostantól a szöveg
  a helyén marad, amíg nincs mi a helyére lépjen.
- **A fájlválasztó alatti jegyzet is azt mondja, ami történt.** Sikeres kinyerésnél: a fájl szövege
  bekerült a mezőbe, maga a fájl nem kerül elküldésre. Eddig ilyenkor is az állt ott, hogy a fájl
  figyelmen kívül marad.

**A legnagyobb feltölthető fájlméret előre látszik (BL-54)**

- A fájltípusok alatt megjelenik, hogy mekkora fájl tölthető fel, **mielőtt** a tanár fájlt
  választana. Eddig ezt sehol nem lehetett megtudni — se a mezőnél, se a súgóban, se a
  fájlválasztóban —, csak akkor derült ki, amikor a feltöltés elakadt.

**A kérdésekben nincs formázás, és a validátor is ezt látja (BL-55, BL-58)**

- **Az AI-tól kapott szövegből csak a szavak kerülnek a kérdésbe.** Háttérszín, félkövér, dőlt,
  hivatkozás — mind lekerül. Egyetlen kivétel az alsó és felső index, mert azok jelentést hordoznak
  (H₂O, m²), nem díszítést.
- **A validátor is a megtisztított szöveget bírálja el.** Amíg nem így volt, a tanár egy tiszta
  kérdés mellett kapott „Módosítandó" minősítést azzal az indoklással, hogy a kérdés kék háttérszínt
  tartalmaz — ami már nem volt sehol. Egy panasz, amivel a tanár nem tud mit kezdeni, rosszabb, mint
  a panasz hiánya.
- **Amit ez elveszít, előre leírva:** a bekezdéshatárokból sortörés lesz, tehát egy többbekezdéses
  magyarázat egy bekezdésként jelenik meg. A szavak megvannak, a tagolás nem.

**Egy tanárnak egyszerre egy generálása futhat (BL-57)**

- Aki elindított egy generálást, addig nem indíthat másikat, amíg az fut. **Piszkozatból tetszőleges
  sok lehet** — a korlát csak a futó munkára szól.
- Az elutasítás nem zsákutca: **megnevezi a futó generálást, és annak a lapjára visz**, ahol a
  haladása látszik és ahol a Megszakítás gombja van. A közben kitöltött beállítások elmentődnek,
  tehát később egyetlen gombnyomással indítható.
- A keret azé, aki az indítás gombot megnyomja. Ezért a listaoldal Létrehozó oszlopa és a „másik
  felhasználó generálását nézed" sáv innentől az indítót nevezi meg.

**A szerzői jogi állítás egy helyen áll (BL-10)**

- A forrásfájlokból kikerült a per-fájl szerzői jogi sor; a beépülő jogi helyzetét egyetlen fájl
  mondja ki, a `COPYRIGHT.txt`. **Ez a fájl része a licenc fájlintegritás-ellenőrzésének**, tehát a
  módosítása vagy törlése ugyanúgy sérült telepítésként jelenik meg, mint bármelyik forrásfájlé.
- **Ami ebből következik az üzemeltetésre:** minden korábban kiadott, fájllistát hordozó licenc
  újrakiadandó.

**Karbantartás**

- A már nem használt böngészős tesztkészlet maradék könyvtárai és hivatkozásai kikerültek a
  repóból és a dokumentumokból.
- Egy mérés lezárt egy tervezett fejlesztést, kód nélkül: a fájlból való szövegkinyerés egy 21
  oldalas, 1 MB-os dokumentumon is **század másodperc alatt** lefut, tehát a hozzá tervezett
  gyorsítótár és várakozásjelző tárgytalan.

## 2026-08-05 — `2026080508` (1.0.0)

**Tesztek a törlési kérésekre és a fájlkorlátokra, és két megvizsgált javaslat elvetése**

- **Az adatvédelmi törlés viselkedése tesztelve.** Hat teszt arról, hogy egy törlési kérés elviszi a
  generálást és a kérdéseket, a **naplósort viszont meghagyja** — a személyhez kötő része nélkül,
  a technikai adatokkal együtt. Külön arról, hogy egy másik tanár anyaga nem tűnik el vele, és hogy
  aki csak szerkesztett vagy jóváhagyott egy kérdést, annak a neve lekerül, de a kérdés marad.
- **A fájlfeldolgozás korlátai tesztelve** — beleértve azt is, hogy egy hétköznapi, negyven képet
  tartalmazó dokumentum **egyik korlátba sem** ütközik.
- **Két javaslat megvizsgálva és elvetve** (a döntések a `dontesek.md`-ben, a specifikációban a
  Felt-037 és Felt-038 sorában): a „nagy fájl, kevés szöveg" esetre kötelező megerősítő
  jelölőnégyzet, és a megjelenés alapú számlálók visszahozása. A figyelmeztetés marad
  figyelmeztetés.

## 2026-08-05 — `2026080506` (1.0.0)

**Az újrapróbálkozás nem tolhatja félre a biztonsági záradékot, és a megszakítás nem törli a naplót (BL-52)**

- **A biztonsági záradék marad az utolsó szó a promptban.** Ha a modell értelmezhetetlen választ ad,
  a rendszer újrakéri — és az ehhez tartozó, **admin által szerkeszthető** szöveg eddig a nem
  szerkeszthető biztonsági záradék *után* került a promptba. Mostantól előtte.
- **A megszakítás nem töröl naplósort többé.** A „megszakítás" eddig kitörölte a tokenkorlátról szóló
  figyelmeztetés naplósorait, holott a szabály az, hogy naplósor nem törlődik. A sor marad, csak
  megjelölést kap, hogy egy visszavont kísérlethez tartozik — így a következő futás képernyőjén nem
  jelenik meg egy olyan figyelmeztetés, ami már nem róla szól. Egy **későbbi** kísérlet
  figyelmeztetése változatlanul látszik.

## 2026-08-05 — `2026080504` (1.0.0)

**Ha ketten dolgoztok ugyanazon a generáláson, a második megvárja az elsőt (BL-51)**

A mentés eddig három lépésben dolgozott: beolvasta a generálást, abból döntötte el, hogy szabad-e
írni, aztán írt. A döntés és az írás közé viszont befért egy másik kérés — és akkor a döntés már egy
állapotra szólt, ami közben megszűnt.

- **A forrásszöveg kicserélődhetett a már futó generálás alól.** A kérdések a régi szövegből
  készültek, a képernyőn az új szöveg állt forrásként, és semmi nem mondta, hogy a kettő nem
  ugyanaz.
- **Két egyszerre megnyomott „Generálás indítása" két futást indított**, két piszkozat-kategóriával
  — és a generálás kétszer fizetődött ki.

Mostantól a mentés, a Vissza gomb és az indítás generálásonként egymást kizárva fut. Aki a
másodikként ér oda, ezt kapja: *„Valaki más éppen ezen a generáláson dolgozik. Várj egy pillanatot,
és próbáld újra."*

## 2026-08-05 — `2026080502` (1.0.0)

**Egy képernyő mentése nem írja felül azt, amit egy másik lapon csináltál (BL-51)**

Aki két lapon dolgozik ugyanazon a generáláson — vagy ketten ugyanazon, mert az eszköz szándékosan
közös —, annak eddig a régebbi lap mentése visszaállíthatta a másik lap munkáját. A mentés
ugyanis a lap megnyitásakor beolvasott **teljes** rekordot írta vissza, tehát minden oszlopot,
azzal az értékkel, ami a megnyitáskor érvényes volt.

- **A forrásszöveg mentése a generálás állapotát is felülírta.** Egy másik lapon elindított
  generálás így „fut" állapotból visszaállhatott „elindítva" állapotba.
- **A beállítások mentése és a Vissza gomb a forrásszöveget is felülírta**, a lap megnyitásakor
  érvényes szövegre — vagyis egy közben elvégzett szerkesztést csendben eldobott.
- **A generálás indítása ugyanígy.**

Mindhárom helyen mostantól csak az adott képernyő saját oszlopai íródnak ki. Ehhez nem kellett
zárolás: a képernyők oszlopai nem utaznak többé együtt.

## 2026-08-05 — `2026080501` (1.0.0)

**A PDF-olvasás nem tart egy egész oldalt a memóriában, és a hibás fájlt nem próbálja félig feldolgozni (BL-49)**

- **Egy oldal tartalmi adatfolyamai eddig egyszerre voltak a memóriában.** Mindegyik kicsomagolódott
  egy tömbbe, és csak a kész tömb után nézte meg bárki az összesített méretet. Mivel ugyanarra az
  objektumra az oldal tetszőlegesen sokszor hivatkozhat, és minden hivatkozás újra kicsomagolódott,
  ennek nem volt plafonja. Mostantól egyszerre egy adatfolyam van a memóriában, legfeljebb 16 MiB.
- **Egy oldal hivatkozásainak száma is korlát lett** (64). Nem összevonás: ugyanaz az objektum
  kétszer felsorolva egy érvényes dokumentumban jogosan adja hozzá kétszer a szövegét.
- **Egy korlát átlépése eddig a drágább feldolgozást indította el.** Az objektumtérkép építése
  ugyanazzal a jelzéssel állt meg egy korlátnál, mint egy olvashatatlan fájlnál, a rendszer pedig az
  utóbbira a régi, teljes fájlt átvizsgáló eljárással válaszol. A kettő szétvált: a korlát mostantól
  visszautasítás.
- **Ha a fájl szerkezete bejárható, de egyetlen betűt sem ad, az visszautasítás** — eddig a rendszer
  a régi eljárással próbált még kihozni belőle valamennyit. A tanár így egy töredéket kapott, és
  semmi nem szólt arról, hogy töredék. Új üzenet nem kellett hozzá: azt kapja, hogy a fájlból nem
  sikerült szöveget kinyerni, és hogy illessze be a szöveget.
- **Az oldalobjektumok nélküli, egyszerűbb vagy régebbi PDF-ek változatlanul működnek.** Ez nem
  hibás fájl, és a régi eljárás jól olvassa.

## 2026-08-05 — `2026080500` (1.0.0)

**A Word-dokumentum mérete csak a másolás után derült ki (BL-50)**

A feltöltő oldalon ez nem látszott, mert ott a fájlválasztó már a feltöltés előtt visszautasítja a
beállított méretnél nagyobb fájlt. A `local_artqtml_extract_text` webszolgáltatás viszont
közvetlenül is hívható, és ott a fájl a hívó saját piszkozat-területéről érkezik, ahová bármelyik
másik Moodle-felület is feltölthetett.

- **A `.docx` út az első műveletként ideiglenes fájlba másolta a dokumentumot**, és csak azután
  nézte meg bármi a méretét. A `.txt` és a `.pdf` út ezt mindig is a fájl megnyitása előtt
  ellenőrizte. A `.docx` mostantól ugyanott, ugyanazzal a 64 MiB-os korláttal
  (`MAX_SOURCE_FILE_BYTES`) — a tanár ugyanazt az üzenetet kapja, mint a másik két típusnál.
- **Mindhárom útra van teszt.** Eddig egyikre sem volt: a méretkorlát a három közül kettőben ott
  volt, de semmi nem szólt volna, ha kiesik.

## 2026-08-04 — `2026080409` (1.0.0)

**A PDF-szövegkinyerés most valóban működik — eddig nem**

A délutáni BL-48-as munka a PDF-olvasót félig javította meg, és a maradék hiba a képernyőn úgy
látszott, mintha a fájlból nem lehetne szöveget kinyerni. A hibákat egy valódi, Word-ből exportált
magyar dokumentum feltöltése hozta elő a localhoston, nem kódolvasás.

- **Minden PDF-feltöltés végzetes hibára futott.** Az olvasó átállt arra, hogy ne adjon vissza
  számot, a hívó oldalán viszont ott maradt, hogy számot vár. TXT és DOCX nem volt érintett.
- **A szöveg java része értelmetlen írásjelsorként jött át.** A betűtípus glifatáblája eddig csak a
  hexadecimális szövegoperandusokra vonatkozott, holott egy Word-export minden oldalt közönséges
  `( )` sztringként ír ki, egybájtos alkészlet-kódokkal. A mért fájl első oldala
  `!"#"$%&'()` volt `A körte: Történelem, egészség és kulináris élvezetek` helyett.
- **A dokumentum címe akkor sem jött át, amikor a többi már igen.** Az egyik betűtípus tömörített
  glifatáblája **egyetlen bájtot** vesztett, mert a kód a lezáró sortörést levágta a bináris adat
  végéről is. A tömörítő ezért az egész táblát elutasította, és a cím betűtípusa csendben tábla
  nélkül maradt. Az objektum saját hosszmezője mostantól az irányadó.
- **A szavak összeragadtak.** `A fenségeséssokoldalúgyümölcs` — a szóköz gyakran önálló
  szövegkiírási művelet, és az olvasó a „csak szóközből álló" darabokat eldobta.
- **Egyetlen hibás bájt az egész feltöltést elbuktatta.** A magyar szövegben lévő, nem UTF-8 bájt
  miatt a Moodle a webszolgáltatás válaszát egészében visszautasította, a tanár pedig azt az
  üzenetet kapta, hogy a fájl nem tartalmaz szöveget. A kinyert szöveg mostantól minden úton
  érvényes UTF-8.
- **Az oldalankénti olvasás üres eredménye után visszalépünk a régi, teljes fájlos olvasásra.**
  Eddig csak akkor, ha az objektumtérkép fel sem épült — így egy fájl, amiből tegnap még jött
  részleges szöveg, ma nullát adhatott.
- **Két erőforráskorlát hétköznapi dokumentumot is elutasított volna.** A DOCX-bejegyzések
  maximuma 256-ról 4096-ra, a PDF-folyamoké 512-ről 8192-re, a kibontási plafon 32 MiB-ról
  192 MiB-ra nőtt. A dekompressziós bombát a tömörítési arány fogja meg, nem a darabszám; a
  feltölthető fájlméretnek pedig mostantól saját korlátja van, nem a kibontásié.

**Súgó- és beállításszövegek, amelyek nem a jelenlegi működést írták le**

- A feltöltési súgó egy olyan védelmet ígért, amit a délutáni döntés kivezetett (a rejtett szöveget
  tartalmazó dokumentum elutasítását). Helyette most azt írja le, ami igaz: a törzs teljes szövege
  bekerül a mezőbe, és a kihagyás szerkezeti, nem megjelenésbeli.
- A típusonkénti AI-instrukció beállításának leírása még azt állította, hogy a tanár szövege a
  rendszerpromptba kerül. Ez ma már nem így van.
- A prompt-injection minták beállítása mostantól megmondja, hogy a négy karakternél rövidebb
  kifejezéseket a rendszer figyelmen kívül hagyja.

**Karbantartás**

- A feltöltő oldal azonosító-egyeztetése kikerült: nem tudott elsülni (az űrlap ugyanabból a
  beküldésből olvasta ki mindkét összehasonlított értéket), és nem is védett semmit, mert a
  Glob-031 szerint bármely jogosult felhasználó szerkesztheti bármely piszkozatot.
- A szövegszámláló JavaScript a plugin verziószámával a címben töltődik be, különben a böngésző a
  régi másolatot hívta volna az új paraméterekkel.

## 2026-08-04 — `2026080408` (1.0.0)

**Biztonság — a prompt-injection védelem több rétegűvé vált**

- **A prompt-injection szűrésnek mostantól kötelező alapja van, amit nem lehet kikapcsolni.**
  Eddig a védelem teljes egészében az admin oldalon megadott listából állt: ha a mező üres volt —
  vagy friss telepítésen még soha nem mentették el —, a szűrés **semmit nem keresett**. Az admin
  mező innentől **hozzáad** a beépített mintákhoz, nem lecseréli őket.
- **A triviális megkerülések már nem működnek.** A szűrés a mintát és a vizsgált szöveget ugyanúgy
  normalizálja, ezért a sortöréssel, extra szóközzel, írásjelekkel, nulla szélességű karakterekkel
  vagy teljes szélességű Unicode-betűkkel megszakított kifejezést is felismeri.
- **Minden AI-kérés kap egy nem szerkeszthető biztonsági utasítást.** Ez tudatos kivétel a plugin
  azon elve alól, hogy minden promptszöveg admin oldalról írható: egy biztonsági határ, amit egy
  szövegmező kiürítésével törölni lehet, nem határ.
- **A tanár feltöltött szövege és a validálandó kérdések strukturált, kifejezetten megbízhatatlanként
  megjelölt adatként mennek a modellhez**, nem folyó szövegként a prompt közepén. Egy hamis
  lezáró jel vagy kapcsos zárójel a dokumentumban így nem tud kilépni a saját mezőjéből.
- Az adminisztrátor korábban megadott listái változatlanul működnek; a mező mostantól vesszőt és
  új sort is elfogad elválasztóként.

**Biztonság — a tanár által írt szöveg többé nem rendszerszintű utasítás**

- **A szabad szöveges nehézségi leírás és a típusonkénti tanári instrukció eddig szó szerint
  bekerült a system promptba** — vagyis a tanár egy űrlapmezőben az adminisztrátor promptjával
  azonos súlyú utasítást írhatott. Ezek mostantól **strukturált, kifejezetten megbízhatatlanként
  jelölt tanári preferenciaként** utaznak a user üzenetben.
- **A system prompt oldalán egy megbízható, admin által szerkeszthető mondat áll a helyükön**, ami
  megmondja a modellnek, hol találja a tanári preferenciát, és hogy az nem írhatja felül a
  rendszerszabályokat, a biztonsági határt vagy a válaszsémát.
- **Az adminisztrátor típusonkénti alapértelmezései változatlanul a system prompt részei maradnak**,
  ha a tanár nem írta felül őket — ez az admin saját szövege, és a viselkedése nem változik.
- **A szabad szöveges nehézségi leírás mostantól átmegy a biztonsági szűrésen is.** Ez volt az
  egyetlen felhasználói mező ezen az űrlapon, amit semmi nem ellenőrzött.
- **A korábbi generálások változatlanul olvashatók.** Ahol a tárolt instrukció megegyezik az
  admin jelenlegi alapértelmezésével, a rendszer admin alapértelmezésként kezeli; ahol eltér,
  tanári preferenciaként. Nincs új adatbázismező és nincs adatvesztés.

**Biztonság — a forrásszövegnek végre van mérethatára**

- **Eddig semmi nem korlátozta a forrásszöveg méretét a szerveren.** A feltöltő oldal számolta a
  karaktereket, szavakat és becsült tokeneket, és pirosra váltott — de a szöveg **így is mentésre
  került és el is ment a szolgáltatóhoz**, bármekkora volt. Egy közvetlenül összeállított
  beküldés még a színezést sem látta.
- **Új admin beállítás: „Forrásszöveg maximális mérete".** 0 esetén a generáló modell
  kontextusablakának **80%-a** — így nem marad hátra egy második szám, amit külön kellene karbantartani.
- **A limit mind a négy úton ugyanaz:** a beírt szövegre, a feltöltött fájlból kinyert szövegre, a
  kettő együttesére, és **közvetlenül az AI-hívás előtt** a már elmentett szövegre is. Ez utóbbi
  azért kell, mert egy régi vagy más úton bekerült generálás sosem járt a feltöltő oldalon.
- **A plugin nem csonkol.** A túl hosszú szöveget visszautasítja — nem kap senki kérdéseket olyan
  dokumentumról, aminek a felét csendben levágtuk.
- **A számláló előre jelzi:** kiírja az érvényes maximumot, 90% fölött sárga, a limit fölött piros,
  és a böngésző nem küldi el az űrlapot. Ez kényelem, nem védelem — a szerver dönt.
- **A havi tokenkeret működése nem változott.** Ez egy kérés bemenetét korlátozza, az a havi
  költést; a kettő független.

**Biztonság — a dokumentumfeldolgozás korlátai és a rejtett szöveg**

- **A DOCX és PDF kitömörítése eddig korlátlan volt.** Egy 2 MB-os feltöltés több száz megabájtnyi
  kicsomagolást deklarálhatott, és semmi nem állt közte és a szerver memóriája között. Mostantól
  kemény, kódszintű felső korlátok vannak — archívum-bejegyzésszám, kitömörített méret,
  tömörítési arány, streamszám és összesített méret —, amiket **admin beállítás nem kapcsolhat ki**.
- **A PDF streamek nem gyűlnek fel a memóriában.** Eddig minden kitömörített stream egy tömbben
  várt a feldolgozás végéig; mostantól egyesével dolgozódnak fel és eldobódnak.
- **A fájltípust a szerver ellenőrzi**, a fájl tényleges első bájtjai alapján, nem a böngésző által
  küldött típus vagy a kiterjesztés alapján.
- **A DOCX-ből csak a dokumentumtörzs látható szövege kerül fel.** A fejléc, a lábléc, a
  megjegyzések, a lábjegyzetek, a törölt szöveg és a mezőutasítások szándékosan kimaradnak — ezek
  jellemző helyei olyan tartalomnak, ami nem része az anyagnak.
- **Minden szöveg bekerül a forrásszöveg mezőbe, formázástól függetlenül.** A plugin **nem
  találgat** arról, hogy egy szövegrész látszik-e: nem ismeri a hátteret, ezért a sötét diára szánt
  fehér betűt láthatatlannak, a szkennelt PDF-ek OCR-rétegét pedig rejtettnek olvasta volna — és
  mindkettő csendben kiürített volna hétköznapi dokumentumokat. A védelmet az adja, hogy a szöveg a
  **képernyőn, szerkeszthető mezőben** áll, mielőtt bárhová menne, és ott fut le minden ellenőrzés.
- **Ami kimarad, azt a szerkezete zárja ki, nem a kinézete.** PDF-ben a JavaScriptet, automatikus
  és indító műveletet vagy beágyazott fájlt hordozó objektumot a plugin **el sem olvassa**;
  DOCX-ben a mezőutasítás, a törölt szöveg és a beágyazott objektum (OLE, vezérlő) marad ki. Ezek
  nem szöveg, amit valaki leírt — de a formázás eltávolítása után annak látszanának.
- **A fejléc és a lábléc továbbra is kimarad**, de ez nem biztonsági, hanem minőségi ok: egy
  lábléc minden oldalon ismétlődik.
- **A feltöltő oldal nem olvassa be még egyszer a teljes fájlt** pusztán azért, hogy hasht
  számoljon — a Moodle már kiszámolta.

**Amit ez a rész sem ígér:** a képekbe égetett szöveget nem olvassa ki, és a `/ToUnicode` tábla
nélküli betűtípusokat nem tudja visszafejteni. Ha egy nagy fájlból kevés szöveg jön, a feltöltő
oldal figyelmeztet — így a néma hibából olyan lesz, amivel a tanár tud mit kezdeni.

**Adatintegritás — a forrásszöveg csak piszkozat állapotban módosítható**

- **Eddig bármelyik generálás forrásszövege átírható volt** a közvetlen `upload.php?id=<n>` címmel,
  akkor is, ha az már futott vagy régen befejeződött. A kérdések nem változtak, a forrásszöveg
  igen — **a kérdések és az anyag, amiből készültek, csendben elváltak egymástól**.
- **A futó generálásnál ez rosszabb:** a folyamat kétszer olvassa a forrásszöveget — a generátor,
  majd a validátor —, tehát egy közbeeső mentéssel **Claude és Gemini más dokumentumot látott
  volna**, és a validátor olyan forráshoz mérte volna a kérdéseket, ami a megírásukkor még nem
  létezett.
- **Mostantól csak piszkozat szerkeszthető.** Minden más állapot a saját, megszokott oldalára
  irányít át, magyarázó üzenettel.
- **A státusz mentés előtt újra ellenőrződik**, frissen az adatbázisból. Enélkül egy megnyitva
  felejtett űrlap percekkel később is felülírhatta volna a közben elindult generálást — ehhez nem
  kell támadó, elég két böngészőfül.
- **A duplikátum-megerősítés sem kerülheti meg.** A munkamenet emlékszik rá, melyik generálásról
  volt szó; arra nem, hogy az milyen állapotban van most.
- **A beküldött rejtett azonosítót a rendszer összeveti a megnyitott generáláséval**, tehát egy
  kézzel összeállított beküldés nem tud másik rekordot megcélozni.

**Ami nem változott, és ez fontos:** a **Glob-031 együttműködési modell érintetlen.** Bárki, akinek
`local/artqtml:use` joga van, továbbra is szerkesztheti **bármely kolléga piszkozatát**. A tiltás
oka mindig az állapot, soha nem a tulajdonos — az üzenet ezért nem is jogosultsághiányról beszél.

**Adatvédelem — a diagnosztikai napló megőrzése és személytelenítése**

- **A törölt generálás naplója eddig egy nem létező rekordra mutatott.** A bejegyzések
  szándékosan túlélik a generálást (Glob-040) — de a `generationid` egy olyan sorra hivatkozott,
  ami már nem volt ott. Az azonosító mostantól **átkerül egy történeti mezőbe**, a hivatkozás
  pedig kiürül: a bejegyzés megmarad és visszakereshető, hamis kapcsolat nélkül. A meglévő
  telepítéseken a frissítés a korábbi árva bejegyzéseket is átmigrálja — **egyetlen naplósor sem
  törlődik**.
- **A teljes diagnosztikai tartalom nem marad örökre.** A system prompt, a válaszséma és a nyers
  AI-válasz — amiben a tanár feltöltött anyaga is benne lehet — **30 nap után automatikusan
  eltávolításra kerül** egy napi feladattal. A naplósor és minden technikai mezője megmarad:
  melyik szolgáltató, milyen státusz, hány token, sikerült-e. A megőrzési idő admin beállítás,
  **nem lehet 0 és nincs „korlátlan"**.
- **A rendes generálástörlés semmit nem személytelenít és nem redaktál.** Ez nem adatvédelmi
  művelet, és a tartalomra épp akkor van a legnagyobb szükség, amikor valaki a hibát keresi.

**GDPR — két hiba, ellentétes irányban**

- **Az adatkiadás nem tartalmazta a törölt generálások naplóit.** A lekérdezés a generálásokon
  keresztül ment, tehát pont azok a bejegyzések maradtak ki, amiket a Glob-040 szándékosan megőriz.
  Mostantól **a felhasználó azonosítója alapján** kerülnek elő, generálás nélkül is.
- **A GDPR-törlés viszont törölte a naplósorokat** — ami ugyanannak a Glob-040 döntésnek mondott
  ellent, a másik oldalról. Mostantól **a sor megmarad, de a felhasználói azonosító és a nyers
  tartalom eltűnik**. Ami marad, az a technikai nyilvántartás, felhasználói azonosító nélkül — az
  már nem személyes adat.
- **A felhasználói azonosító nullázása önmagában kevés volt.** Egy „névtelen" sor, amiben ott áll
  valakinek a saját dokumentuma, nem névtelen abban az értelemben, ami számít.
- Az adatvédelmi nyilatkozat a napló **mind a 16 oszlopát** felsorolja; eddig négyet.

**PDF-szövegkinyerés — a PowerPoint-exportokból eddig szinte semmi nem jött át (BL-48)**

- **Egy valódi, 21 oldalas oktatási PDF-ből 64 karakter jött ki a kb. 17 500-ból.** Nem határeset:
  a Microsoft Office PDF-exportja beágyazott CID betűtípust ír, amiben a szöveg **kétbyte-os
  glifaazonosítókként** áll, nem betűkként. Ezekhez a betűtípus saját `/ToUnicode` táblája kell,
  amit a kód nem keresett meg.
- **Ehhez új réteg kellett, nem bővebb minta:** a `/ToUnicode` csak hivatkozási láncon érhető el
  (oldal → erőforrások → betűtípus → CMap), amit reguláris kifejezéssel nem lehet végigjárni. Új
  függőség viszont **nem** kellett hozzá.
- **Ha a szerkezet nem olvasható, a régi teljes fájlszkennelés fut le változatlanul** — egyetlen ma
  működő fájl sem romolhat el emiatt.
- **Mellékesen megszűnt egy sokkal nagyobb pazarlás:** a régi eljárás **minden** adatfolyamot
  kicsomagolt, a képeket is — a mért fájlon 23,47 MB-ot azért, hogy 0,16 MB szöveghez jusson. A
  munka **99,3%-a** képadat volt. Ez már nem csak lassú volt: a ma délelőtt bekerült 32 MB-os
  korlátba egy valamivel képgazdagabb prezentáció **belefutott volna**, pedig a szövege 0,2 MB.
- **A szóközök.** A régi kód minden operandus közé szóközt tett — innen jött a
  `K ar ak t er ek` alakú kimenet. Glifánként dekódolt szövegnél ez használhatatlan lenne; mostantól
  elválasztó nélkül fűz össze, és **sortörést csak akkor tesz, ha a függőleges pozíció ténylegesen
  elmozdul** (0,5 pont). Ez mérés eredménye: minden pozicionálásnál törni a `2026-ban`-t
  `2026 - ban`-ra vágta szét.
- **Új figyelmeztetés:** ha egy nagy fájlból nagyon kevés szöveg jön, a feltöltő oldal szól. **Nem
  utasítja vissza** — egy sok képet és kevés szöveget tartalmazó dia jogos eset —, de a tanár így
  tudja, hogy a beillesztés a jobb út. Eddig 64 karakter „sikeres feltöltésnek" számított.

**Amit ez nem jelent, és ezért itt áll:** a prompt injection **nem szűnt meg**. Ez védelmi
mélység, nem garancia — egy átfogalmazott, más nyelvű vagy közvetett utasítás átmehet a szűrőn. Amit
a védelem valóban ad: a nyilvánvaló kísérletek kiszűrése, a modell számára egyértelmű határ a
utasítás és az anyag között, kötött válaszséma, szerveroldali ellenőrzés — és az, hogy **minden
kérdést tanár hagy jóvá**, mielőtt bárhova bekerül.

## 2026-07-31 — `2026073100` (1.0.0)

**Admin felület**

- **A beépített prompt sablon többé nem jelenik meg az admin oldalon.** Eddig a Generátor és a
  Validáló fülön a mező alatt teljes egészében kiíródott, „Default:" felirattal — sima szövegként,
  bárki számára, aki az oldalt megnyitotta. A mező mostantól üresen indul, és a leírása mondja meg,
  mi történik: **üresen hagyva a beépített sablon fut**, amit ide beírsz, az teljes egészében
  lecseréli. A generálás működése nem változik.
- A prompt sablon mezőjének leírása kimondja azt is, hogy **a forrásszöveg nem a sablonba kerül**,
  hanem külön üzenetként megy — eddig a specifikáció is tévesen az ellenkezőjét állította.

## 2026-07-30 — `2026073003` (1.0.0)

**Felület**

- A táblázatokban **többé nem szakad el szó a szó közepén**. Korábban a „True/False" cellában
  „True/F alse", a kérdés nevénél „PWT1-IH- 0001" jelenhetett meg, ha az oszlop szűkebb volt a
  szónál. A hosszú, megszakíthatatlan szövegek (beillesztett hivatkozás) továbbra is tördelődnek,
  hogy a táblázat ne lógjon ki.
- A **dátumok** egységesen `2026.07.30 08:12` alakúak: nincs bennük napnév, hónapnév és de./du.
  jelölés. Ez a lista és a jóváhagyó oldal Dátum oszlopára, a duplikátum-figyelmeztetésre és az
  admin oldal modell-lista feliratára vonatkozik. A licensz oldal dátumai szándékosan maradtak a
  Moodle nyelvfüggő alakjában.
- A **CSS szerkesztő oldal kiírja a saját címét.** Eddig az oldal tetején csak a webhely neve
  állt, és sehol nem szerepelt, melyik oldalon jár a felhasználó.
- A jóváhagyó oldalról **eltűnt a Tags hivatkozás.** Ugyanoda vezetett, mint a Szerkesztés, csak a
  natív szerkesztő címke szakaszához görgetett — egy harmadik hivatkozás a legszűkebb oszlopban.
- A soronkénti **Jóváhagyás** ugyanolyan súllyal jelenik meg, mint a Szerkesztés, az Előnézet és a
  Törlés mellette. Kitöltött kék gombként kiabált a három hivatkozás fölött, holott azok éppúgy
  módosítanak. A viselkedése nem változott: továbbra is űrlapgomb, nem hivatkozás — jóváhagyni
  linkre kattintva nem lehet.

**Jogosultság**

- Aki generálást indít, **azonnal tudja szerkeszteni a piszkozat kérdéseket** a Moodle natív
  kérdésszerkesztőjében. Eddig ehhez egy rendszergazdának kézzel be kellett léptetnie a
  felhasználót a piszkozat kurzusba; enélkül a Szerkesztés és az Előnézet hivatkozás jogosultsági
  hibára vitt.
- A hozzáférést a plugin adja, a generálás indításakor, egy **saját, szűk szerepkörrel**. Ez
  pontosan három jogosultságot visz — kurzus megtekintése, kérdés szerkesztése, kérdés előnézete —
  és nem beiratkozás: a felhasználó nem jelenik meg a kurzus résztvevői között, és a kurzus
  kérdésbank-listája továbbra is üres marad számára. A piszkozat kérdések ezután is kizárólag a
  jóváhagyó oldalról érhetők el.
- **Más generálásának kérdését is szerkesztheti**, ahogy eddig is megnyithatta és jóváhagyhatta —
  de a Szerkesztés ilyenkor **megerősítést kér**, és megnevezi, kié a generálás. Az Előnézet nem
  kér megerősítést, mert nem módosít.

## 2026-07-29 — `2026072900` (1.0.0)

**Felület**

- A lista, a feltöltés, a kérdés beállítások, a státusz és a jóváhagyó oldal **szélesebb lett**.
  Korábban a Moodle „standard" laptípusát használták, amelyet a Boost téma 830 képpontra korlátoz
  teljes képernyős böngészőben - emiatt a jóváhagyó táblázat oszlopai összenyomódtak, a szavak a
  cellákon belül elszakadtak, és a Műveletek oszlop lelógott a jobb szélen. Az oldalak mostantól
  1120 képpontig szélesednek.

## 2026-07-29 — `2026072800` (1.0.0)

Az első kiadás óta felgyűlt javítások és két új viselkedés. A demo első tesztkörén talált mind az
öt hiba lezárva.

**Javítások**

- A táblázatok fejlécei nem törnek szó közben. Korábban „Kér dés nev e" és „Neh ézs égi mó d"
  jelent meg a jóváhagyó és a lista oldalon.
- A törlés és a modellművelet oldala már beállítja a lap kontextusát, így sikeres törlés után nem
  jelennek meg fejlesztői figyelmeztetések, és az átirányítás is megtörténik.
- A „Megnyitom a meglévőt" gomb a duplikátum-figyelmeztetésen, valamint a naplóbejegyzések
  hivatkozásai a generálás **aktuális** állapotához illő oldalra visznek.
- Frissítéskor a plugin helyreteszi azokat a piszkozat-kérdéskategóriákat, amelyeket egy korábbi
  verzió hibásan a kérdésbank gyökerére akasztott. Ez a hiba az érintett kurzus kérdésbankját
  megnyithatatlanná tette.

**Licensz**

- A lejárati figyelmeztetés a **lejárat dátumát** nevezi meg, a kvótafigyelmeztetés pedig a
  **felhasznált arányt** — korábban a hátralévő napok, illetve a hátralévő darabszám jelent meg.
- A kvótafigyelmeztetés alapértelmezett küszöbe 90%-ról **80%**-ra változott.
- **Új:** a licensz kizárólag azon a webhelyen érvényes, amelyre kiállították. Eltérés esetén a plugin
  blokkoló figyelmeztetést ad, de a Licensz fül elérhető marad, hogy a helyes licenszfájl feltölthető
  legyen. Az összevetés a gépnév alapján történik, tehát egy http → https váltás nem érvényteleníti.
- **Új:** kérdésszám alapú licensznél a generálás nem indul el, ha többet kérnél, mint amennyi a
  keretben maradt. A generálás piszkozatként menthető marad, és egyetlen kérés sem megy ki az AI-nak.

**Biztonság**

- A kérdéstípusonkénti **AI instrukció** mező tartalma ugyanazon az SQL- és prompt-injection
  vizsgálaton megy át, mint a feltöltött forrásszöveg. Korábban szűretlenül került a
  rendszerpromptba.
- A mező mellett és az admin kapcsoló leírásában is szerepel, hogy az ide írt szöveg eljut az
  AI-hoz, tehát befolyásolja a generált kérdéseket.

**Csomag**

- A telepíthető csomag már nem tartalmazza a `tests` könyvtárat, és a licensz fájlintegritás-manifesztje
  sem sorolja fel. **A korábban kiállított, manifesztet tartalmazó licenszfájlokhoz újat kell kérni**,
  mert azok még a tesztfájlokat is felsorolják.

**Szövegek**

- A magyar felület egységesen tegez.
- A követelményhivatkozások (pl. „(Jov-023)") kikerültek a beállítások leírásaiból.
- A „büntetés" helyett mindenütt „levonás" szerepel.
- A validáló AI indoklása a webhely nyelvén készül.

## 2026-07-27 — `2026072700` (1.0.0)

Első kiadás.
