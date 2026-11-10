# Laravel Manual

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-manual/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-manual/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-manual.svg)](https://packagist.org/packages/intragile/laravel-manual)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-manual.svg)](https://packagist.org/packages/intragile/laravel-manual)
[![License](https://img.shields.io/packagist/l/intragile/laravel-manual.svg)](LICENSE)

**A repóban élő, Markdown formátumú felhasználói kézikönyv**, amelyet a szerver renderel és
keres benne, és **beállítható hozzáférésű JSON API-n** ad ki. Egy konzolparancs CI-ban ellenőrzi.

[English README → README.md](README.md)

- **Headless.** A csomag adatot ad, a súgóoldalt az SPA-d vagy a Blade nézeted rajzolja.
- **Kézikönyv kódként.** A fejezetek Markdown-fájlok a repódban, ugyanabban a pull requestben
  kerülnek átnézésre, mint a funkció. Nincs adatbázis, nincs CMS.
- **Fordítás külön rendszer nélkül.** Nyelvenként egy könyvtár. Ha egy fejezet hiányzik a
  fordításból, a visszaesési lánc következő nyelvén jön, és ezt jelzi.
- **Szerveroldali keresés.** Nyelvenkénti szótövezés, súlyozott rangsor, egyszerű szöveges
  kivonatok. A teljes tartalom nem hagyja el a szervert.
- **Kézikönyv-jelölések.** Hivatkozhatsz másik fejezetre, az alkalmazásod útvonalaira és külső
  URL-ekre kulccsal, tehetsz be ikonokat és kiemelő dobozokat (`tip`, `warning`, ...). Mindez
  szerveroldalon oldódik fel.
- **Biztonságos HTML.** A nyers HTML kikerül, a veszélyes linkek kiesnek, így a `html` mező
  `v-html`-be való.
- **Te döntöd el, ki éri el.** Nyilvános, csak bejelentkezettek, gate, vagy kikapcsolva.
- **A hibás kézikönyv nem megy ki.** A `manual:check` CI-ban jelzi a hiányzó fordítást, az eltérő
  szerkezetet, a törött linket és a nyers HTML-t.

## Telepítés

```bash
composer require intragile/laravel-manual
```

PHP 8.3+ és Laravel 12 vagy 13 kell hozzá. A service provider automatikusan betöltődik. A configot
csak akkor publikáld, ha módosítani akarod:

```bash
php artisan vendor:publish --tag=manual-config
```

## Gyors kezdés

Előbb olvasd el a hozzáférésről szóló részt. Az alapbeállítással (az `access` értéke
`authenticated`, az automatikus route-ok pedig csak az `api` csoportban vannak) a kérés csak akkor
megy át, ha az **alapértelmezett guard** már felismeri a felhasználót. Egy szokásos tokenes API-nál
(Sanctum) ez nem így van, ezért a végpontok a bejelentkezett felhasználónak is **401-et** adnak.

Ajánlott: kapcsold ki az automatikus route-okat, és a saját, már hitelesítő route-csoportodban
regisztráld őket. Előbb publikáld a configot:

```bash
php artisan vendor:publish --tag=manual-config
```

Utána a publikált fájlban kapcsold ki az automatikus route-okat, és állítsd be a nyelveket. A
`locales` az API által kiszolgálható összes nyelv, a `source_locale` pedig az a nyelv, amelyen
elsőként írsz:

```php
// config/manual.php
'path' => resource_path('manual'),
'locales' => ['hu', 'en'],
'source_locale' => 'hu',
'fallback' => ['en'],

'routes' => [
    'enabled' => false,
    'prefix' => 'manual',
    'middleware' => ['api'],
    'name' => 'manual.',
],
```

```php
// routes/api.php
use IntrAgile\Manual\Facades\Manual;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Manual::routes();   // GET /api/v1/manual, /manual/chapter, /manual/search
});
```

A `Manual::routes()` a configból maga teszi hozzá a saját előtagját (`manual`) és a route-név
előtagot (`manual.`), így a fenti csoport csak a külső előtagot és a hitelesítést adja. A
`routes.middleware`-t **nem** alkalmazza: az a tömb csak az automatikus route-csoporté.

A publikált configban a teljes `routes` tömböt tartsd meg. A Laravel a csomag configját csak a
legfelső szinten fésüli össze, így az általad megadott `routes` tömb lecseréli a csomagét; a
kihagyott kulcsok az alábbi táblázat alapértékeit kapják.

Ezután írd meg az első fejezetet (lásd a következő részt), és ellenőrizd:

```bash
php artisan manual:check
```

## A kézikönyv megírása

```
resources/manual/
  _meta.php            a fa: szakaszok, sorrendjük és fejezeteik (nyelvfüggetlen)
  _links.php           opcionális: a [[link:kulcs]] célpontjai (lásd lent)
  hu/
    _sections.php      a szakaszcímek ezen a nyelven
    getting-started/
      intro.md
      first-steps.md
  en/
    _sections.php
    getting-started/
      intro.md
      first-steps.md
```

A **`_meta.php`** a fát adja vissza. A szakaszazonosító a kulcs; az `icon` szabad szöveg, amelyet a
csomag változtatás nélkül ad tovább a kliensnek, az `order` a szakaszok sorrendjét adja, a
`chapters` pedig a fejezet-slugokat olvasási sorrendben. A fejezet címe `{szakasz}/{fejezet}`,
itt `getting-started/intro`.

```php
// resources/manual/_meta.php
return [
    'getting-started' => ['icon' => 'rocket', 'order' => 1, 'chapters' => ['intro', 'first-steps']],
    'admin' => ['icon' => 'settings', 'order' => 2, 'chapters' => ['users']],
];
```

A **`{locale}/_sections.php`** minden szakaszazonosítóhoz megadja a címét az adott nyelven:

```php
// resources/manual/hu/_sections.php
return ['getting-started' => 'Első lépések', 'admin' => 'Adminisztráció'];
```

A **számozás** a `_meta.php`-beli helyzetből jön: az első szakasz második fejezete az `1.2`. Mivel a
számok nem függenek a nyelvtől, **minden fejezetnek kell fájl a forrásnyelven**, különben a
számozás nyelvenként eltolódna. A `manual:check` ezt számon kéri.

A **szakaszazonosítók és a fejezet-slugok** csak `[A-Za-z0-9_-]+` alakúak lehetnek.

### A fejezetfájlok

Egy fejezet a `{path}/{locale}/{szakasz}/{fejezet}.md` fájl.

- Az első `# Cím` a fejezet címe. ATX alakot használj (`# Cím`): a setext cím (`Cím` alatt
  `=====`) a `manual:check` szerint hiba, és a renderelő sem olvassa. Egy fejezetben pontosan egy H1
  van.
- A cím utáni első bekezdés az **összefoglaló**, a fában és a keresésben ez látszik.
- Szakaszokhoz `##` és `###` való. Kapnak `id`-t, és bekerülnek a `headings`-be. A `####` és a
  mélyebb a `manual:check` szerint hiba.
- A táblázat, lista, idézet és a kerített kódblokk szabványos CommonMark (a GFM-táblázat is).
- **Csak a kerített kódblokk számít kódnak.** A `manual:check` a 4 szóközzel behúzott kódblokkot
  nem ismeri fel: a sorait szövegként vizsgálja. A kódot mindig három visszafelé aposztróffal vagy
  `~~~`-vel kerítsd.
- Nyers HTML nem megengedett (a renderelő kiveszi, a `manual:check` jelzi). A `List<String>` és
  hasonló szöveget tedd kódjelek közé.

### Kiemelő dobozok

```markdown
:::tip

Mentsen gyakran.

:::
```

A megengedett típusok a `callouts` config értékei (alapból `tip`, `warning`, `note` és `steps`).
Szabályok:

- A `:::típus` és a záró `:::` a **0. oszlopban** áll, külön sorban, előtte és utána üres sorral.
  Listába vagy idézetbe nem kerülhet; a `manual:check` jelzi.
- A dobozok egymásba ágyazhatók. Az ismeretlen típus és a lezáratlan doboz hiba a `manual:check`
  szerint.

A példa magázó, mert a kézikönyv a végfelhasználónak szól; a fejlesztői leírások tegeznek.

Így renderelődnek: `<div class="manual-callout" data-type="tip">...</div>`.

### Tokenek

| Token | Eredmény |
|---|---|
| `[[manual:szakasz/fejezet]]`, címkével: `[[manual:szakasz/fejezet\|címke]]` | `<a class="manual-link" data-slug="szakasz/fejezet">`, hivatkozás másik fejezetre |
| `[[route:név]]`, címkével: `[[route:név\|címke]]` | `<a class="manual-route" data-route="név">`, hivatkozás az alkalmazásod egy képernyőjére |
| `[[link:kulcs]]`, címkével: `[[link:kulcs\|címke]]` | `<a class="manual-external" href="..." target="_blank" rel="noopener noreferrer">` |
| `[[icon:név]]` | `<i class="manual-icon" data-icon="név" aria-hidden="true"></i>` (címke nélkül) |

Címke nélkül a név jelenik meg. A táblázatban a `\|` az, ahogy a Markdown-táblázat cellájában a
függőleges vonalat írni kell; a saját fejezeteidre ugyanez a szabály érvényes:

- A nevek csak `[A-Za-z0-9._/-]` karaktereket tartalmazhatnak.
- **A táblázatcellában álló szó szerinti `|` jelet `\|` alakban kell írni, a tokenen belül is.**
  A cellában álló `[[manual:a/b\|címke]]` token, a címkéje `címke`.
- **A token címkéje nem tartalmazhat soron belüli jelölést** (`**félkövér**`, `` `kód` `` stb.);
  az ilyen token szó szerinti szöveg marad. Tartsd egyszerűnek a címkét.
- A tokent csak szövegben oldja fel, kódrészletben és kódblokkban soha. Ha egy tokent **szó szerint
  akarsz megmutatni**, tedd kódrészletbe: `` `[[manual:a/b]]` ``. A zárójelek kódolása
  (`&#91;&#91;`) kódon kívül nem segít: az is tokenként értelmeződik.
- A `manual:check` jelzi a hibás alakú tokent, az ismeretlen fajtát, a `_meta.php`-ban nem szereplő
  `manual` célt, az ismeretlen `link` kulcsot, és ha a `known_routes` meg van adva, az ismeretlen
  route-ot.

A **külső linkek** a `_links.php`-ból jönnek (vagy a `links` configból: tömb, vagy ilyen fájl
útvonala). Az alak `kulcs => nyelv => URL`:

```php
// resources/manual/_links.php
return [
    'status' => ['hu' => 'https://example.com/hu/status', 'en' => 'https://example.com/en/status'],
];
```

Az URL-t a fejezet nyelvére választja, aztán `en`-re, aztán az elsőre. Csak `http`, `https` és
`mailto` URL lesz link; minden más, és az ismeretlen kulcs is, egyszerű címkeszövegként jelenik meg.

## Beállítások

| Kulcs | Alap | Jelentés |
|---|---|---|
| `path` | `resource_path('manual')` | A kézikönyv könyvtára. |
| `locales` | `['en']` | Az API által elfogadott nyelvek. Sorold fel az összes fordítást. A forrásnyelv mindig engedélyezett. |
| `source_locale` | `en` | Az a nyelv, amelyen minden fejezetnek léteznie kell; a visszaesési lánc utolsó lépése. |
| `fallback` | `['en']` | További nyelvek, sorrendben, amelyeket akkor próbál, ha a kért nyelven hiányzik a fejezet, vagy a kért nyelv nincs beállítva. |
| `access` | `authenticated` | `public`, `authenticated`, gate neve vagy `disabled`. Lásd lent. |
| `routes.enabled` | `true` | A route-ok automatikus regisztrálása. Kapcsold ki, ha a `Manual::routes()`-t magad hívod. |
| `routes.prefix` | `manual` | URL-előtag. |
| `routes.middleware` | `['api']` | Az automatikus route-csoport middleware-je. |
| `routes.name` | `manual.` | Route-név előtag (`manual.index`, `manual.chapter`, `manual.search`). |
| `callouts` | `['tip', 'warning', 'note', 'steps']` | A megengedett `:::típus` dobozok nevei. |
| `links` | `null` | A `[[link:]]` térképe: tömb, fájlútvonal, vagy `null`, ekkor a `{path}/_links.php`, ha létezik. |
| `known_routes` | `null` | A `[[route:]]` által használható route-nevek, a `manual:check` ellenőrzi. `null` kihagyja az ellenőrzést. Lista, vagy iterálható értéket adó callable, pl. `'App\Support\Routes::names'`. A callable szöveges callable vagy Closure lehet: a tömb-callable-t a rendszer névlistaként olvassa. A `config:cache` miatt a szöveges callable az ajánlott. |
| `stemmers` | `hu`-ra magyar, `en`-re angol | Nyelv => `Stemmer`-t megvalósító osztály. Lásd: Keresés. |
| `search.min_term_length` | `3` | Az ennél rövidebb keresőszavakat figyelmen kívül hagyja. |
| `search.limit` | `10` | A találatok alapszáma. |
| `cache.store` | `null` | Cache store; `null` az alapértelmezettet használja. |
| `cache.prefix` | `manual` | A cache-kulcsok előtagja. |

## Hozzáférés

Az `access` dönti el, ki hívhatja az API-t. Az ellenőrzés a csomag minden route-jának részét képező
middleware-ben fut, tehát az automatikus route-okra és a `Manual::routes()`-ra is vonatkozik.

| Érték | Viselkedés |
|---|---|
| `public` | Bárki. |
| `authenticated` | Be kell jelentkezni, különben **401**. |
| gate neve | A `Gate::allows(név)` dönt. Az elutasított, nem bejelentkezett látogató **401**-et, az elutasított bejelentkezett felhasználó **403**-at kap. |
| `disabled` | Minden route **404**. |

Gate neve nem lehet `public`, `authenticated` vagy `disabled`: ezek a szavak mindig a táblázatban
szereplő viselkedést jelentik.

Az `authenticated` a `$request->user()`-t nézi az **alapértelmezett guardon**. A csomag saját
hitelesítést nem ad, az alap route-csoport pedig csak `['api']`, ezért további beállítás nélkül sem
a Sanctum token, sem a munkamenet nem ismerhető fel, és 401 jön. Két megoldás:

1. **Ajánlott:** `routes.enabled = false`, és a `Manual::routes()` hívása a saját hitelesített
   csoportodban, mint a gyors kezdésben; vagy
2. az automatikus route-ok megtartása és a hitelesítő middleware hozzáadása:

   ```php
   'routes' => [
       'enabled' => true,
       'prefix' => 'manual',
       'middleware' => ['api', 'auth:sanctum'],
       'name' => 'manual.',
   ],
   ```

Ha a Sanctum-guardod nem az alapértelmezett, biztonságosabb a gate vagy az egyedi csoport.
`public`-ot csak olyan tartalomra használj, amit nyilvános weboldalra is kitennél.

A körülvevő csoport middleware-jei (például az `auth:sanctum`) a csomag saját middleware-jei
**előtt** futnak, így az `Accept: application/json`-t kényszerítő előtt is. Az `Accept` fejléc
nélkül érkező nem bejelentkezett látogatót ezért az alkalmazásod saját, hitelesítetlen kérésekre
vonatkozó kezelése utasítja el, amely alapból a bejelentkező route-ra irányít át. Állítsd be, hogy
az alkalmazásod az API-ra JSON-t adjon, például a `bootstrap/app.php`-ban:
`$exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'))`.

A gate a nem bejelentkezett látogatóra is lefut, de a Laravel csak akkor hívja meg nála a gate
callbackjét, ha annak első, user paramétere nullable, vagy `null` az alapértéke. Ha a gate-en a nem
bejelentkezett látogatót is át akarod engedni, így deklaráld:

```php
use App\Models\User;

Gate::define('view-manual', fn (?User $user): bool => $user === null || $user->can_see_help);
```

Az a callback, amely kötelezően `User`-t vár, vagy egyáltalán nincs paramétere, minden nem
bejelentkezett látogatót 401-gyel utasít el.

## API

Minden válasz JSON. A csomag a route-jain `Accept: application/json`-t kényszerít, és hagyományos
kivételeket dob (`AuthenticationException`, `ValidationException`, `NotFoundHttpException`), így a
hibákat **az alkalmazásod kivételkezelője formázza**, például `application/problem+json`-ra.

| Végpont | Leírás |
|---|---|
| `GET {prefix}` | A fa: a szakaszok a fejezeteikkel (slug, szám, cím, összefoglaló). |
| `GET {prefix}/chapter?slug=szakasz/fejezet` | Egy renderelt fejezet. 404, ha a slug ismeretlen, vagy a láncban egyetlen nyelven sincs hozzá fájl; 422, ha a `slug` hiányzik vagy 200 karakternél hosszabb. |
| `GET {prefix}/search?q=...` | Keresési találatok. 422, ha a `q` hiányzik vagy 200 karakternél hosszabb. |

Minden végpont elfogadja a `?locale=` paramétert is (legfeljebb 20 karakteres szöveg).

```json
{
  "data": [
    {
      "id": "getting-started",
      "icon": "rocket",
      "title": "Első lépések",
      "chapters": [
        { "slug": "getting-started/intro", "number": "1.1", "title": "Bevezetés", "summary": "Mi ez, és kinek szól." }
      ]
    }
  ],
  "locale": "hu",
  "fallback": false
}
```

```json
{
  "data": {
    "slug": "getting-started/intro",
    "number": "1.1",
    "title": "Bevezetés",
    "summary": "Mi ez, és kinek szól.",
    "section": "getting-started",
    "position": 1,
    "total": 3,
    "readingMinutes": 2,
    "html": "<p>...</p>",
    "headings": [ { "anchor": "elso-lepesek", "text": "Első lépések", "level": 2 } ],
    "prev": null,
    "next": { "slug": "getting-started/first-steps", "title": "Első lépések" },
    "locale": "hu",
    "fallback": false
  }
}
```

```json
{
  "data": [
    { "slug": "admin/users", "section": "admin", "title": "Felhasználók", "snippet": "... egyszerű szöveges részlet ...", "score": 12 }
  ],
  "locale": "hu",
  "fallback": false
}
```

- A `number` szöveg (`"2.1"`); a `prev` és a `next` `{slug, title}`, vagy `null` az első, illetve az
  utolsó fejezetnél; a láncban fájl nélküli fejezeteket átugorja.
- Az a fejezet, amelynek a láncban egyetlen nyelven sincs fájlja, kimarad a fából, és a `number`,
  `position`, `total`, `prev` és `next` csak a nyelvhez ténylegesen létező fejezeteket számolja.
- A fejezet `locale` mezője a ténylegesen beolvasott fájl nyelve, a `fallback` pedig akkor `true`,
  ha ez eltér a kért nyelvtől. A fejezetválasznak nincs legfelső `locale`-ja.
- A fa és a keresés legfelső `locale` mezője a kérésből meghatározott nyelv: a kért nyelv, ha be van
  állítva, különben a `fallback` első beállított nyelve, végül a `source_locale`. A `fallback` akkor
  `true`, ha a kérést a saját nyelvén nem lehetett teljesíteni. Ez **nyelvszintű**: ha egyetlen
  fejezet hiányzik egy beállított fordításból, és a visszaesésből jön, az nem kapcsolja be a fa vagy
  a keresés `fallback`-jét. Ahhoz a fejezet saját `fallback` mezőjét olvasd.
- A `html` megtisztított (lásd Biztonság), és `v-html`-lel való beillesztésre szánt.
- Minden más szöveges mező (`title`, `summary`, `headings[].text`, a szakaszok címe, a `prev` és a
  `next` címe, valamint a keresési `snippet`) **egyszerű szöveg**: szövegként kell megjeleníteni,
  soha nem HTML-ként.

### Nyelvfeloldás

A nyelvet a kérésből ebben a sorrendben oldja fel:

1. `?locale=`;
2. az `Accept-Language` minden címkéje, a kliens minőségi sorrendjében. A `q=0` címkét („nem
   elfogadható”) figyelmen kívül hagyja. Minden címke előbb a teljes alakjával, aztán az
   elsődleges címkéjével próbálkozik: a `pt-BR` a beállított `pt-BR`-re (vagy `pt_BR`-re), ennek
   hiányában a `pt`-re illik; a `hu-HU` a `hu`-ra;
3. az alkalmazás nyelve (`app()->getLocale()`), **csak ha a kliens egyáltalán nem küldött
   használható nyelvet**;
4. `source_locale`.

Az 1. és 2. lépés első olyan jelöltje nyer, amely szerepel a `locales`-ben vagy a `source_locale`,
így az `Accept-Language: de, en;q=0.9` az `en`-t szolgálja ki, ha a `de` nincs beállítva. Ha a
kliens kért nyelvet, de egyik sincs beállítva (mondjuk csak `?locale=de`), a kézikönyv a
visszaesési láncból (lásd lent) jön, a fa és a keresés pedig `fallback: true`-t jelez: a kliens így megtudja,
hogy a saját nyelve nem elérhető. A hibás alakú érték (`?locale=../x`) figyelmen kívül marad.

### Visszaesési lánc

A kért nyelvhez a fejezetet ebben a sorrendben keresi: a kért nyelv, aztán a `fallback`-ben szereplő
nyelvek (csak a beállítottak), végül a `source_locale`. Az első megtalált fájl jön. A nem beállított
kért nyelvet (nincs a `locales`-ben, és nem a `source_locale`) kihagyja, és soha nem jut el a
fájlrendszerig: a lánc ilyenkor csak a beállított `fallback` nyelvekből, majd a `source_locale`-ból
áll. Ha a `locales` `['hu', 'en']`, a `source_locale` `hu`, a `fallback` pedig `['en']`, a `de`
kérés `en` nyelven jön `fallback: true`-val, az olyan fejezet pedig, amelynek nincs `en` fájlja,
`hu` nyelven. Üres (`[]`) `fallback` esetén a `source_locale`-ból jön. A config `fallback` kulcsa a
**lánc** (mely nyelveket próbálja); a válasz `fallback` jelzője csak azt mondja meg, hogy a
kiszolgált nyelv nem a kért nyelv.

## A kézikönyv ellenőrzése

```bash
php artisan manual:check
php artisan manual:check --locale=en     # a forrásnyelv és ez a nyelv; ismételhető
```

Minden beállított nyelvet ellenőriz (a forrásnyelvet és a `locales`-t), és a fejezet és a nyelv
megjelölésével jelzi:

- `_meta.php`: hiányzik, nem tölthető be, hibás szakaszok vagy fejezetek, érvénytelen azonosító vagy
  slug, ismétlődő fejezet, fejezet nélküli kézikönyv;
- `_sections.php`: hiányzó szakaszcímek, ismeretlen szakaszkulcsok;
- fájlok: a `_meta.php`-ban nem szereplő Markdown-fájl (árva), a forrásnyelvről hiányzó fejezet, a
  fordításból hiányzó fejezet;
- szerkezet: nincs cím, több H1, setext cím, nincs összefoglaló, `####` vagy mélyebb cím,
  ismeretlen, lezáratlan, behúzott vagy idézetben álló `:::` jelölő, üres sorok nélküli `:::`;
- tartalom: nyers HTML, hibás vagy fel nem oldott token, törött `manual` hivatkozás, ismeretlen
  `link` kulcs, ismeretlen route (csak `known_routes` mellett), olyan link-URL, amelyből nem lenne link;
- **paritás**: az a fordítás, amelynek a blokkszerkezete (címek, listák, táblázatok, dobozok,
  kódblokkok, bekezdések) eltér a forrásnyelvétől. A lefordított kódkommentár vagy a más sortörés
  nem eltérés; a hiányzó bekezdés vagy szakasz az.

Bármilyen hibánál kiírja az üzeneteket, és nem nulla kilépési kóddal tér vissza, így pull request
ellenőrző lépés lehet:

```yaml
# .github/workflows/tests.yml
- name: Check the manual
  run: php artisan manual:check
```

A `--locale` a `locales`-ben nem szereplő nyelvvel hiba. A kerített kódblokkot minden sorszabály
figyelmen kívül hagyja, a nyers HTML és a token ellenőrzése a soron belüli kódrészleteket is.

A `manual:clear` a cache-t érvényteleníti (lásd lent).

## Keresés

A keresés a szerveren fut, és az indexét lustán építi fel. A keresőkifejezést (legfeljebb 200
karakter) szavakra bontja, az ékezeteket lehántja (az `ertesites` megtalálja az `Értesítési`-t),
és minden szót a **feloldott** nyelv szótövezőjével vág szótőre. Egy fejezet akkor találat, ha
**minden** szó előfordul benne részszövegként. A találatokat súlyozott előfordulás rangsorolja:
cím 6, alcímek 3, összefoglaló 2, törzs 1. A `search.min_term_length`-nél rövidebb szavakat
figyelmen kívül hagyja.

Beépítve: a `HungarianStemmer` és az `EnglishStemmer`, könnyű toldalékvágók, amelyek legalább négy
karaktert meghagynak. A szótövező nélküli nyelven úgy keres, ahogy beírták. A szótövezés csak a
keresőkifejezésre vonatkozik; a tartalomban úgy keres, ahogy írva van.

A visszaeső tartalomban a **kért** nyelv szótövezőjével keres: az angol kérésre megjelenő magyar
fejezetben az angol szótövezővel.

Saját szótövező regisztrálásához valósítsd meg az `IntrAgile\Manual\Search\Stemmer`
interfészt, és nyelv szerint add meg. Az osztálynak argumentum nélküli konstruktor kell, és
kisbetűs ASCII szót kap (az ékezetek már le vannak hántva). A nyelvnek szerepelnie kell a
`locales`-ben is; a `stemmers` bejegyzés önmagában nem engedélyezi:

```php
namespace App\Search;

use IntrAgile\Manual\Search\Stemmer;

final class GermanStemmer implements Stemmer
{
    public function stem(string $normalizedWord): string
    {
        if (strlen($normalizedWord) < 5) {
            return $normalizedWord;
        }

        return preg_replace('/(en|er|e|n|s)$/', '', $normalizedWord) ?? $normalizedWord;
    }
}
```

```php
// config/manual.php
'stemmers' => [
    'hu' => \IntrAgile\Manual\Search\HungarianStemmer::class,
    'en' => \IntrAgile\Manual\Search\EnglishStemmer::class,
    'de' => \App\Search\GermanStemmer::class,
],
```

Az a bejegyzés, amelynek osztálya nem létezik, vagy nem valósítja meg a `Stemmer`-t, kimarad, vagyis
az adott nyelven nincs szótövezés.

A találat `snippet` mezője **egyszerű szöveg**, a fejezet első találat körüli részéből vágva. Nem
megtisztított HTML: szövegként jelenítsd meg (`{{ hit.snippet }}`), ne `v-html`-lel, vagy escape-eld.

## Programozott elérés

```php
use IntrAgile\Manual\Facades\Manual;

Manual::tree('hu');                    // szakaszok a fejezeteikkel
Manual::chapter('admin/users', 'hu');  // tömb vagy null
Manual::markdown('admin/users', 'hu'); // ['markdown' => ..., 'locale' => ...] vagy null (cache nélkül)
Manual::search('számla', 'hu', 5);     // találatok listája, opcionális limit
```

A `Manual::spy()` és a `Manual::swap()` működik, mert a facade gyökere a `ManualManager` szolgáltatás.
A visszatérési alakok a fenti JSON-szerkezetek (a `data` burkoló nélkül). A `_meta.php`-ban nem
szereplő `slug` `null`-t ad. A kívánt nyelvet add át; a HTTP-réteggel ellentétben a facade nem
olvassa a kérést.

## Frontend-tippek

- A fejezet `html` mezője biztonsággal beilleszthető `v-html`-lel. A kattintásokat **egyetlen
  delegált figyelővel** kezeld a konténeren, a linkek egyenkénti bekötése helyett: keresd a
  legközelebbi `[data-slug]`-ot, és nyisd meg azt a fejezetet, vagy a legközelebbi `[data-route]`-ot,
  és told be a route-nevet a routeredben. A linkeknek `tabindex="0"` és `role="link"` jár, ezért az
  Entert is kezeld.
- Az `[[icon:név]]` `<i class="manual-icon" data-icon="név">` lesz. A `data-icon`-t képezd le a saját
  ikonkészletedre (CSS-sel, vagy cseréld ki az elemet); a fában a szakasz `icon` mezője ugyanilyen
  szabad szöveg.
- A dobozokat a `.manual-callout[data-type="tip"]` választóval stílusozd (és `warning`, `note`,
  `steps`, vagy amit beállítottál). A táblázatok `.manual-table-wrap` burkolóban vannak, hogy
  görgethetők legyenek.
- A `h2` és `h3` `id`-ja megegyezik a `headings` `anchor` mezőjével, így tartalomjegyzéket építhetsz,
  és a `#anchor`-ra görgethetsz.
- Az SPA küldjön `?locale=` vagy `Accept-Language` értéket, hiszen a felhasználó nyelvét általában
  kliensoldalon tartja. A fejezet `fallback` mezőjéből jeleníthetsz meg „még nincs lefordítva”
  megjegyzést.
- A keresési `snippet`-et szövegként jelenítsd meg, soha ne HTML-ként.

## Nyilvános API és szemantikus verziózás

A csomag a [szemantikus verziózást](https://semver.org/lang/hu/) követi. Az 1.0.0-tól kezdve az
alábbiak bármelyikét érintő, visszafelé nem kompatibilis változás a következő főverzióig vár:

- a `Manual` facade metódusai: `tree()`, `chapter()`, `markdown()`, `search()` és `routes()`;
- az `IntrAgile\Manual\Search\Stemmer` interfész (hogy a saját szótövezők tovább működjenek), valamint a
  beépített szótövező osztályok: `HungarianStemmer`, `EnglishStemmer` és `NullStemmer`;
- a `ManualManager` publikus metódusai (`tree()`, `chapter()`, `markdown()` és `search()`, a
  konstruktora nem);
- a config kulcsai és jelentésük;
- a route-ok, a nevük és a lekérdezési paramétereik, valamint a fent leírt JSON-szerkezetek;
- a tartalom formátuma: `_meta.php`, `_sections.php`, `_links.php`, a token- és dobozszintaxis és a
  renderelt jelölés (`manual-link`, `manual-route`, `manual-external`, `manual-icon`,
  `manual-callout`, valamint a `data-slug`, `data-route`, `data-icon`, `data-type` attribútumok);
- a `manual:check` és a `manual:clear` parancs, a kapcsolóik és a kilépési kódjuk. Az egyes
  üzenetek szövege nem tartozik ide, és új ellenőrzések alverzióban is jöhetnek.

Nem tartozik ide: minden `@internal` jelölésű osztály és metódus (a controller, a middleware-ek, a
resource-ok, a parancsosztályok, a nyelvfeloldó), valamint a facade mögötti osztályok: a
`ManualRepository`, a `ContentTree`, a `ManualSearch`, a `ManualRenderer` és a `ManualValidator`.
A facade-ot érdemes használni.

## Cache

A fa, az egyes fejezetek (a renderelt HTML-lel együtt) és a keresési index a beállított store-ban
vannak gyorsítótárazva. A bejegyzések csak tömböket és szövegeket tartalmaznak, így az objektumok
visszaállítását tiltó store-okkal (`cache.serializable_classes = false`) is működnek. A kulcs
ezekből áll össze:

- a `cache.prefix`;
- `CACHE_VERSION`, a csomag egy konstansa, amelyet a tárolt alak vagy tartalom változásakor emelnek;
- a cache **generáció**;
- a bejegyzés fajtája (`tree`, `chapter` vagy `search`) és a tényleges nyelv;
- a kimenetet befolyásoló config hash-e (`locales`, `source_locale`, `fallback`, `callouts`, a
  feloldott linkek, és egy külső linkfájl módosítási ideje és mérete);
- a tartalom ujjlenyomata: a kézikönyv könyvtárának minden `.md` és `.php` fájljának útvonala,
  módosítási ideje és mérete;
- a fejezet slugja, a fejezet-bejegyzéseknél.

Ha egy deploy módosít egy fájlt vagy a configot, a változás anélkül jelenik meg, hogy bármit
törölni kellene. Minden bejegyzés egy nap után lejár, hogy a régi kulcsok ne halmozódjanak fel.

A hiányzó tartalmi útvonal nem hiba, hanem üres kézikönyvet eredményez. A `php artisan manual:check`
parancsot ezért a telepített buildre vagy image-re is érdemes lefuttatni, nem csak a repositoryban.

A `php artisan manual:clear` növeli a generációt: minden régi bejegyzés egyszerre elérhetetlenné
válik, és magától lejár. **Bármilyen** cache store-on működik (nincs címke, nincs kulcslistázás). A
futó workerek a következő kérésnél már az új generációt olvassák, mert a repository, a keresés és a manager
kötései **scoped** hatókörűek: kérésenként vagy jobonként új példány készül, ezért az Octane és a queue
workerek biztonságosak.

Korlát: az előző szerkesztéssel azonos másodpercben végzett módosítás, ha a fájl mérete nem
változik, nem észlelhető. Ilyenkor futtasd a `manual:clear`-t. A gyakorlatban ez csak a fájl
kézi, helyben szerkesztésekor számít; a deploy lecseréli a fájlokat.

## Biztonság

A markdown nyers HTML **eltávolításával** készül (`html_input: strip`), a veszélyes linksémákat,
például a `javascript:`-et pedig kiveszi (`allow_unsafe_links: false`), így a `html` mező biztonsággal
beilleszthető egy oldalba. A `[[link:]]` URL-jének `http`, `https` vagy `mailto` kell legyen. A
nyelv a fájlnévbe kerülés előtt csak a beállított listából származhat, a fejezet-slugnak pedig
szerepelnie kell a `_meta.php`-ban, mielőtt útvonal készül belőle, ezért kéréssel nem lehet
tetszőleges fájlt olvastatni a csomaggal. Lásd: [SECURITY.md](SECURITY.md). A keresési `snippet`
egyszerű szöveg: escape-eld.

## Tesztelés és minőség

```bash
composer test
composer analyse
vendor/bin/pint --test
```

A `composer analyse` a PHPStan-t `--memory-limit=1G`-vel futtatja. A saját CI-odban is adj meg
`--memory-limit`-et (`vendor/bin/phpstan analyse --memory-limit=1G`); a legmagasabb szinten a PHP
alap memóriakorlátja kevés lehet.

## Licenc

MIT. Lásd: [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu).
