# KSeF dla Dolibarr ERP CRM

<p align="center" width="100%">
<img alt="Icon" src="./img/ksef.png" width="20%" />
</p>

## [In English](README.md)

## Opis

Kompletna integracja e-fakturowania KSeF (Krajowy System e-Faktur) dla Dolibarr. Generuj i wysyłaj faktury FA(3) do Krajowego Systemu e-Faktur, obsługuj łańcuchy korekt i importuj faktury przychodzące. W użyciu produkcyjnym od momentu uruchomienia systemu w lutym 2026 roku.

## Funkcje

- Generowanie, podpisywanie i przesyłanie faktur FA(3) XML do KSeF (tryb online i offline)
- Faktury korygujące (KOR) ze śledzeniem łańcucha korekt i rozliczeniem płatności
- Pobieranie, synchronizacja i import faktur przychodzących z dopasowaniem dostawców i produktów
- Import zbiorczy z automatycznym tworzeniem dostawców i produktów
- Monitoring systemu KSeF przez API Latarnia/Lighthouse z ostrzeżeniami o awariach
- Integracja kursów walut NBP dla faktur walutowych
- Wizualizacje PDF faktur w stylu KSeF dla faktur wychodzących i przychodzących
- Automatyczny kod QR na fakturach PDF (online + offline)
- Obsługa zwolnień podatkowych z konfigurowalną podstawą prawną
- Konfigurowalne notatki, pola dodatkowe oraz numery zamówień/umów w FA(3) XML
- Uwierzytelnianie per środowisko (Test/Demo/Produkcja) tokenem lub certyfikatem
- Wyszukiwanie firm w systemie GUS/REGON na podstawie numeru NIP (wymagany klucz API GUS – instrukcje w sekcji „Jak korzystać”)
- Endpointy REST API
- Wykluczenie kontrahentów z KSeF (np. klienci B2C)
- Wbudowana dokumentacja "Jak używać"
- Zaplanowane zadania do sprawdzania statusu, synchronizacji przychodzących, ponawiania offline i monitoringu KSeF

## Dokumentacja
Pełna dokumentacja modułu znajduje się w zakładce "Jak używać". Przygotowaliśmy jednak również bardziej szczegółową dokumentację ze zrzutami ekranów, dostępną na naszej stronie internetowej w języku angielskim i polskim. Jeśli napotkasz problemy lub uważasz, że czegoś brakuje, daj nam znać, chętnie to uzupełnimy!

https://inpointautomation.com/pl/content/modul-ksef-dla-dolibarr/

## Wymagania

### Wymagania systemowe

- Dolibarr: Przetestowano i działa na v22 i nowszych
- PHP: 7.4 lub nowsza
- Rozszerzenia PHP:
    - `OpenSSL`
    - `cURL`
    - `DOM`
- Moduł kodów kreskowych Dolibarr: do generowania kodów QR w PDF-ach

### Konto KSeF

- Ważny polski NIP (numer identyfikacyjny podatnika) skonfigurowany w ustawieniach firmy.
- Aktywne konto KSeF z tokenem autoryzacji wygenerowanym z oficjalnego portalu KSeF dla wybranego środowiska
  (Test, Demo lub Produkcja).

## Instalacja

Zalecana metoda instalacji to instalacja z pliku ZIP.

1. Pobierz najnowszy plik ZIP modułu ze [strony wydań](https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases)
2. W Dolibarr przejdź do Strona główna → Konfiguracja → Moduły/Aplikacje
3. Wybierz kartę Wdróż/zainstaluj moduł zewnętrzny
4. Prześlij plik ZIP modułu
5. Znajdź moduł Integracja KSeF na liście i włącz go

## Wspieranie projektu

Zapraszamy do forku tego modułu lub do przesyłania PR-ów, aby wesprzeć społeczność Dolibarr

Instrukcje budowania znajdują się w README dla deweloperów [DEV.md](DEV.md)

## Licencje

### Kod główny

Ten projekt jest licencjonowany na warunkach AGPLv3+

Szczegóły znajdują się w pliku [LICENSE](LICENSE).

### Biblioteki stron trzecich

Ten moduł zawiera obecnie następujące biblioteki stron trzecich w katalogu `lib/vendor`:

- [phpseclib/phpseclib](https://github.com/phpseclib/phpseclib) - Licencja MIT - Wykorzystywana do uwierzytelniania API
  i szyfrowania faktury

- [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding) - Licencja MIT - Wykorzystywana
  przez phpseclib

- [composer](https://github.com/composer/composer) - Licencja MIT - Pliki Composera w katalogu vendor, nie są używane w czasie działania

## Zastrzeżenie prawne

Ten moduł nie jest oficjalnie zatwierdzony ani powiązany z:

- Ministerstwem Finansów Polski
- Operatorami systemu KSeF (Krajowy System e-Faktur)

Korzystanie z tego modułu nie gwarantuje zgodności z polskimi przepisami podatkowymi. Użytkownicy są odpowiedzialni
za zapewnienie, że ich praktyki fakturowania są zgodne z obowiązującym prawem polskim.

## Podziękowania

Dziękujemy Éricowi Seignemu i wtyczce FacturX (https://registry.inligit.fr/cap-rel/dolibarr/plugin-facturx). Gdyby nie
możliwość nauki na podstawie ich modułu, ten projekt zajęłby znacznie więcej czasu

Ten moduł został opracowany przez InPoint Automation Sp. z o.o.

## Changelog (In English)
Pełna historia wersji znajduje się w [CHANGELOG.md](CHANGELOG.md).
