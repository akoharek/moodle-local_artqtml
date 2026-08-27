# Kattintásos teszt — ArtQTML Light (`local_artqtml`)

Böngésző-alapú tanári regresszió localhoston (tipikusan Moodle 4.5.x, `http://127.0.0.1:8080/`).
**Nem módosít kódot** — csak ellenőrzés. Eredményt táblázatban rögzítsd:

`| # | Művelet | URL | Eredmény (OK / Részleges / Várt hiba / HIBA) | Megjegyzés |`

Felhasználók: `teacher1` (manageall / manager), `teacher2` (editingteacher, manageall nélkül), jelszó pl. `Passw0rd!`.

---

## Kötelező körök

| Kör | Tartalom | Mikor |
|-----|----------|--------|
| **Alap** | Lista, approve, revoke, approve-all, delete, move, más user gen. | Minden nagyobb változás után |
| **A — Edit mentés** | Külső szerkesztés + lock + **questioncode** a listán | Minden approve/UI változás után |
| **B — Unhappy path** | Move kategória/sor nélkül, moved/locked sorok, hiányzó param | Release előtt / edge-case javítás után |
| **C — Jogosultság** | editingteacher manageall nélkül | S-02 / capability változás után |

---

## A — Edit mentés és külső zárolás (TC-CLICK-A-002)

**Kapcsolódó spec:** Jov-047, Jov-048 · **Behat:** `review_questions.feature` — „External edit locks row but list keeps questioncode”

**Előfeltétel:** Befejezett generálás, legalább egy **még nem áthelyezett** draft sor (pl. `0827-IH-0003`).

**Lépések**

1. Jóváhagyó oldal megnyitása (`/local/artqtml/approve.php?generationid=…`).
2. Egy sor **Szerkesztés** → Moodle kérdésszerkesztő (`question.php`).
3. **Question name** mező módosítása (pl. suffix: ` [EDIT-TEST]`) → **Mentés**.
4. Vissza a jóváhagyó oldalra (Tovább / vissza link / approve URL).

**PASS (mind kötelező)**

| # | Ellenőrzés | Elvárt |
|---|------------|--------|
| 1 | Sor azonosító a táblázat „Kérdés neve” oszlopában | **Változatlan `questioncode`** (pl. `0827-IH-0003`) — **nem** a bankban mentett új név |
| 2 | Bankban mentett név | A suffix / módosítás **csak** a Moodle szerkesztőben / bankban látszik — **nem** a plugin listán |
| 3 | Zárolás | **Locked** (Zárolt) jelvény a soron |
| 4 | Utoljára szerkesztette | Frissül (pl. „Last edited by: …”) |
| 5 | Jóváhagyás / áthelyezés | **Approve** és **Move** (egyedi és bulk) **tiltva** ezen a soron |
| 6 | Törlés | Draft **Delete** továbbra is elérhető (generálás törlése külön szabály — Jov-043) |

**FAIL jelek (gyakori téves elvárás)**

- A `[EDIT-TEST]` vagy átírt bank-név megjelenik az approve listán → **teszt hiba**, nem plugin bug.
- Locked badge hiányzik mentés után → **plugin bug** (S-01 / observer).

**Implementációs hivatkozás:** a lista címke a plugin `questioncode` mezője (`approve_renderer.php`), nem a Moodle `question.name` szinkronja.

---

## A — Edit megnyitás (TC-CLICK-A-001)

Rövid ellenőrzés (Behat: „Teacher edits a draft question”):

1. **Szerkesztés** → editor megnyílik.
2. **Question name** mező értéke = **`questioncode`** (pl. `REV1-IH-0001`), nem tetszőleges szabad szöveg.

---

## B — Unhappy path (összefoglaló)

Részletes eredmény: [Unhappy path browser test](ee9d0a63-9cb9-40b9-a437-76b110e9ec92) subagent.

| # | Próba | Várt |
|---|--------|------|
| B1 | Move selected, nincs kategória, nincs sor | Gomb disabled |
| B1b | Move selected, nincs kategória, van sor | Gomb enabled → kattintásra hibaüzenet (*„Select a question bank category first.”*) — UX finomítás opcionális |
| B2 | Move selected, nincs sor, van kategória | Gomb disabled |
| B3 | Delete selected (2 draft) | Siker + confirm |
| B4 | Approve moved soron | Moved badge, nincs Approve |
| B5 | Delete moved sor | Checkbox disabled, nincs Delete |
| B6 | Generating / failed / started status | Megfelelő read-only / action gombok |
| B7 | Második concurrent generation | Nincs globális lock (per-user) — dokumentált viselkedés |
| B8–9 | Hiányzó/érvénytelen `id` / `generationid` | Moodle param / DB hiba |

---

## C — editingteacher manageall nélkül

Részletes eredmény: [Editingteacher no-manageall test](e7d40358-743b-4195-8eab-0a4c9d831bed) subagent — 6/6 PASS.

---

## Alap kör — minimum checklist

- [ ] Lista + szűrők (nincs `[[kulcs]]`)
- [ ] Új generálás (paste; AI opcionális — internet kell)
- [ ] Approve / Revoke / Approve all accepted
- [ ] Preview
- [ ] Delete draft (+ confirm)
- [ ] Move selected → Moved + Megnyitás (cél bank)
- [ ] Más user generáció: olvasás vs. mutálás (manageall függő)
- [ ] **A-002:** Edit mentés → Locked + questioncode a listán
