# A fejlesztői eszközkészlet — mi van telepítve, hol, és hogyan állítható vissza

Ez a fájl azért létezik, mert **eddig semmi nem rögzítette.** Az ellenőrző programok kézzel kerültek
a gépre, a Moodle saját függőségi listájába, és ha egy Moodle-frissítés vagy egy visszaállítás
elvitte volna őket, semmiből nem derült volna ki, mi volt bennük (BL-21).

## Mik ezek és mire valók

Egyik sem része a pluginnak. A kódot olvassák, és jelzik, ha baj van vele; éles üzemben soha nem
futnak, a tanár sosem találkozik velük.

| eszköz | mit csinál |
|---|---|
| **phpcs** (PHP CodeSniffer) | a **formai** ellenőr: behúzás, elnevezés, kötelező fejlécek, megjegyzések alakja. Hibát és figyelmeztetést különböztet meg; a kapu csak a hibákon bukik |
| **moodle-cs** | nem program: maga a **Moodle szabálykönyve**, amit a phpcs olvas |
| **phpstan** | a **logikai** ellenőr: nem létező függvény hívása, rossz típusú érték, kezeletlen eset. Ez az, amelyik valódi hibát talál |
| **spaze/phpstan-disallowed-calls** | a PHPStan **„security” rétege** ezen a projekten: tiltott veszélyes / végrehajtási / insecure hívások (pl. `eval`, `exec`, `sha1` jelszóhashként). **Nem** hivatalos PHPStan Security csomag, és **nem** teljes taint-elemzés — csak disallowed-call szabályok |
| **Semgrep CE** | külön CI workflow (`.github/workflows/semgrep.yml`), `p/php` szabálykészlet — szabad SAST a privát repohoz; nem SonarCloud |
| **phpmd** | bonyolultság-mérő: túl hosszú metódus, sok elágazás, sok paraméter, nem használt változó. **Helyben fut a `check.sh`-ban, de nem kapu** — lásd lejjebb |
| phpcsutils, phpcsextra, pdepend | a fentiek függőségei, nem önállóan használt eszközök |

## Verziók — ezeknek egyezniük kell a CI-vel

Az első négy a `tools/devtools/composer.json`-ban **pontos verzióval** rögzített; a többi az ő
függőségük, azokat a composer oldja fel.

| csomag | helyi verzió | CI |
|---|---|---|
| `phpstan/phpstan` | **2.2.5** — rögzítve | `ci.yml` → `PHPSTAN_VERSION: 2.2.5` + `tools/devtools` composer install — **rögzítve** |
| `spaze/phpstan-disallowed-calls` | **4.14.0** — rögzítve | ugyanez a `tools/devtools` composer install hozza; a `phpstan.neon` include-olja |

A `composer.json` `audit.block-insecure: false` szándékos: a rögzített `phpcs` 3.13.5 advisory
egyébként megállítaná a `composer install`-t (a CI ugyanerre a configra támaszkodik;
`--no-audit` nem használható, mert a moodle-plugin-ci PATH-on lévő Composer túl régi).
Verzióemeléskor nézd meg az advisory-t.
| `phpmd/phpmd` | **2.15.0** — rögzítve | nem fut a CI-ben, szándékosan |
| `squizlabs/php_codesniffer` | **3.13.5** — rögzítve | a `moodle-plugin-ci ^4` hozza |
| `moodlehq/moodle-cs` | **v3.7.0** — rögzítve | a `moodle-plugin-ci ^4` hozza |
| `dealerdirect/phpcodesniffer-composer-installer` | v1.2.1 | függőség |
| `phpcsstandards/phpcsutils` | 1.2.3 | függőség |
| `phpcsstandards/phpcsextra` | 1.5.1 | függőség |

**Fontos (finding #11):** a PHPStan-nak **nincs** hivatalos „security” extension csomagja.
A `spaze/phpstan-disallowed-calls` a választott helyettesítő: dangerous / execution / insecure
hívások tiltása. A Semgrep CE külön workflow-ban fut (`semgrep.yml`), nem a PHPStan része.

## A phpmd külön eset: fut, de nem kapu

**Egy pontosítás, mert korábban félrevezetően fogalmaztam.** Azt írtam, „telepítve volt, de soha
semmi nem hívta". Ez a **kapukra** igaz — sem a CI, sem a `check.sh` nem futtatta —, de úgy hangzik,
mintha sosem lett volna haszna. **Egy egész refaktorálást ez mért ki.**

A `archivum/REFACTOR_RUN_LOG.md` szerint a három god-class szétbontásánál (2026-07-25) a phpmd adta
a számokat: *„PHPMD (codesize+design) a 3 fájlon: 16 komplexitás-találat"*, és van benne egy egész
szakasz **„Refaktor-célfájlok komplexitása — előtte/utána"** címmel. Ez a jó használata: mérőműszer
egy konkrét munkához, előtte-utána.

**Miért nem kapu.** A phpcs és a phpstan azon bukik el, aminek **van helyes válasza**: vagy jól van
formázva, vagy nem; vagy létezik az a metódus, vagy nem. A phpmd viszont **küszöbökön** dolgozik —
„tíznél több elágazás sok" —, és ezek a küszöbök önkényesek. Kapuként a build olyasmin bukna el,
amiről a fejlesztő ránézésre azt mondja, hogy így van jól; az ilyen kaput pedig pár hét alatt
megtanulja mindenki megkerülni.

Az igazi kérdése — **nem nőnek-e vissza a szétbontott osztályok** — nem naponta merül fel, hanem
félévente. Arra a mérőműszer való, nem a kapu.

**Ezért 2026-07-31-től:** a `check.sh` lefuttatja, kiírja, amit talált, és **nem állítja a kilépési
kódot**. A CI-ben nem fut. A `tests/`, a `tools/` és a `node_modules/` ki van zárva belőle — egy
teszt hosszú törzse nem karbantartási adósság.

**A `db/upgrade.php` is kizárva, és ez nem kényelmi döntés.** A Moodle előírt alakja egy hosszú
lánc `if ($oldversion < X) { … }` blokkokból, minden korábbi verzióhoz egy; minden plugin upgrade
fájlja így néz ki, és nem is szabad átalakítani. A phpmd 2026-07-31-én **CC=94-et, 766 sort és
`9223372036854775807` NPath-ot** mért rá — az utóbbi a PHP legnagyobb egész száma, vagyis a számolás
túlcsordult. Bennhagyva a három találata elnyomná azt, ami valóban ránézést érdemel.

### Alapállapot — ehhez hasonlítunk

**2026-07-31, az első teljes futás: 56 találat**, ebből 3 a `db/upgrade.php`-n. A kizárás után
**53** marad — ez a szám az összehasonlítási alap.

**Ez nem romlás, és ezt meg lehet mutatni.** A god-class refaktorálás naplója (2026-07-25) név
szerint rögzítette, hogy a metódusok komplexitása **nem** csökkent, mert a törzsüket bitre
változatlanul költöztették: *„verify()=17, upload()=15, status_panel()=12"*. A mai futás ugyanezt a
három számot adja, az új helyükön — `license_file_integrity`, `license_persistence`,
`license_renderer`. Az `approve.php`, ami a refaktor után 0 találattal zárt, ma sincs a listán.
**A szétbontott osztályok tehát nem nőttek vissza**; az 53 találat az a metódus-szintű bonyolultság,
amihez a refaktor szándékosan nem nyúlt.

**A négy legmagasabb, ha egyszer sor kerül rá:**

| hely | ciklomatikus komplexitás |
|---|---|
| `question_semantic_validator::validate()` | **33** |
| `validate_questions_task::merge_results()` | 26 |
| `approve_renderer::questions_table()` | 25, és 369 sor |
| `generate_questions_task::build_prompt()` | 22 |

Ha ez a szám érdemben 53 fölé megy, az azt jelenti, hogy új bonyolultság keletkezett — akkor érdemes
megnézni. Amíg körülötte mozog, nincs teendő.

**A `.devtools/composer.lock` a gépen jön létre, és nem kerül a repóba** — a `.devtools` a Moodle
fája alatt van, nem a pluginé. A visszaállításhoz a manifeszt elég; a függőségek verziói ebben a
táblázatban állnak, ha valaha pontosan ugyanaz kell.

**A phpstan verzióját a `tools/check.sh` minden futáskor összeveti a `ci.yml`-ben rögzítettel**, és
eltérésnél megáll. Így a „helyben zöld, CI-n piros" nem egy bukott buildből derül ki, hanem a push
előtt.

**Új verzióról a Dependabot értesít**, pull requesttel (`.github/dependabot.yml`), nem egy piros
futás. A PHPStan és a spaze extension a `tools/devtools/composer.json`-ból jön (CI és helyben
ugyanaz); a `check.sh` a `ci.yml` `PHPSTAN_VERSION` sorát is összeveti a helyi binárissal.

## Hol laknak

**Elsődleges (2026-08-10 óta, finding #11):** `local/artqtml/tools/devtools/vendor/` — ide kell a
`composer install`, mert a `phpstan.neon` spaze include útvonalai ehhez a plugin-fához képest
relatívak. A `vendor/` gitignore-olt; a manifeszt a repóban van.

**Másodlagos / korábbi:** `~/projektek/moodle/.devtools/` — 2026-07-31 óta. A Moodle gyökere alatt
van, tehát a konténer látja Docker-beállítás módosítása nélkül, de **nem a Moodle-é**. A `check.sh`
először a plugin `tools/devtools`-et keresi; ha csak a `.devtools` van meg, a phpcs/phpmd még
futhat, de a PHPStan spaze include hiányában megáll.

Ha máshova kerül a régi készlet, add meg: `AIQ_TOOLCHAIN=<útvonal> bash tools/check.sh`. A szkript
minden futáskor kiírja, melyik készletet használta.

**Ahol korábban laktak, és miért kellett elhozni őket onnan** (BL-21): a Moodle saját
`composer.json`-jában, kézzel bejegyezve — +11 sor a manifesztben, +773 a lock fájlban.

- Egy `git checkout -- .` vagy egy hard reset a Moodle fáján **nyom nélkül elvitte volna az
  egészet**, és semmi nem rögzítette, mi volt benne.
- A Moodle verziója szándékosan rögzített (BL-20). Amíg a linterek is abban a fájlban ültek, egy
  linter frissítése és egy Moodle-frissítés ugyanaz a művelet volt.
- **További pluginok jönnek ugyanerre a Moodle-ra.** Mindegyik egy olyan fájlból örökölte volna az
  eszközöket, ami egyiküké sem.

**A művelet elvégezve:** a `composer.json` és a `composer.lock` bájtra azonos a tiszta `v4.5.12`
taggel, és a Moodle fáján nincs módosított fájl.

**Ami szándékosan ott maradt:** a régi binárisok a Moodle `vendor/bin`-jében. A `git checkout` a
manifesztet állítja vissza, könyvtárat nem takarít. A `check.sh` nem választja őket — az új hely az
elsődleges —, de egy kézzel indított `vendor/bin/phpcs` igen. A takarítás a Moodle saját PHPUnit-ját
és Behat-ját is újratelepítené, ezért külön kör.

## A költöztetés — ha valaha újra kell csinálni

**A composer a konténerben van, nem a gépen** (`/usr/local/bin/composer`, PHP 8.3.32) — ellenőrizve
2026-07-31-én. A telepítés ezért ugyanúgy a konténerből megy, ahogy az ellenőrzések. A konténer
ugyanazt a fát látja, csak más néven, ezért a `.devtools` útvonal a gépről is ugyanoda mutat.

**A manifeszt fájl, nem parancssor.** A `tools/devtools/composer.json` a repóban áll, verziókövetve,
és pontosan a fenti táblázat verzióit rögzíti. Ezért a telepítés nem gépelésből áll, hanem másolásból
és egy `composer install`-ból — és ha bármi elviszi a készletet, ugyanez a két lépés hozza vissza.

Ebben a fájlban benne van az `allow-plugins` blokk is, és ez **nem elhagyható**. A **moodle-cs egy
phpcs-bővítmény**, amit egy telepítő plugin regisztrál; engedély nélkül a `moodle` szabálykönyv nem
lesz elérhető a phpcs számára. Ez ugyanaz a blokk, ami a Moodle `composer.json`-jába is bekerült — a
kézi szerkesztés innen eredt.

**1. lépés — telepítés a plugin `tools/devtools` könyvtárába** (ez kell a `phpstan.neon`
include-oknak és a CI-nek). A composer a gépen gyakran nincs a PATH-on; a konténerben van
(`/usr/local/bin/composer`, PHP 8.3.32).

```
cd ~/projektek/moodle-docker && docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec -T webserver sh -lc 'cd local/artqtml/tools/devtools && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction'
```

**Opcionális — régi `.devtools` hely** (csak phpcs/phpmd tartalék, ha a plugin vendor hiányzik):

```
cd ~/projektek/moodle && mkdir -p .devtools && cp local/artqtml/tools/devtools/composer.json .devtools/
cd ~/projektek/moodle-docker && docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec -T webserver sh -lc 'cd .devtools && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction'
```

**2. lépés — a `COMPOSER_ALLOW_SUPERUSER=1` nem díszítés:** a konténerben a composer **root-ként
fut**, és ilyenkor magától kikapcsolja a bővítményeket — pontosan azt, amire a moodle-cs
regisztrálásához szükség van. Enélkül a telepítés lefut, de a `moodle` szabálykönyv hiányozni fog,
és a phpcs egy nehezen érthető hibával áll meg.

**3. lépés — ellenőrzés.** A kimenet első sorának már azt kell írnia, hogy *„plugin tools/devtools
… — ez a kívánatos állapot"*:

```
cd ~/projektek/moodle/local/artqtml && bash tools/check.sh
```

**4. lépés — és csak ha a 3. zöld:** a Moodle fájának visszaállítása eredeti állapotba.

```
cd ~/projektek/moodle && git checkout -- composer.json composer.lock
```

**Ebben a sorrendben.** A visszaállítás törli a régi készletet; ha előbb történne, és az újban
valami hiányzik, egyszerre lenne oda mindkettő.

**5. lépés — a régi bináris maradékok.** A `git checkout` a `composer.json`-t állítja vissza, de a
`vendor/` könyvtárban lévő fájlokat nem takarítja. Amíg ott vannak, a `check.sh` nem választaná őket
(az új hely az elsődleges), de egy kézzel indított `vendor/bin/phpcs` igen. Ha teljesen el akarod
tüntetni őket:

```
cd ~/projektek/moodle-docker && docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec -T webserver sh -lc 'composer install --no-interaction'
```

Ez a visszaállított manifeszt szerint rendezi újra a `vendor/`-t. **Nem sürgős, és nem is
kockázatmentes** — a Moodle saját fejlesztői függőségeit is újratelepíti (PHPUnit, Behat), amikre a
tesztfuttatás támaszkodik. Külön körben, nem ugyanabban.

## Visszaállítás, ha valami elvitte

Ugyanaz az 1. és 2. lépés, mint fent — a manifeszt másolása és egy `composer install`. Ez az
egyetlen ok, amiért a verziótáblázat és a `tools/devtools/composer.json` a repóban áll, és nem csak
a gépen.
