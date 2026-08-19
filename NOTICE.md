# Third-party notices

vpn-forge is distributed under the [MIT License](LICENSE). It builds on the
third-party software listed below, each under its own licence. This file is a
summary for convenience; the authoritative licence text for every PHP package
ships in its own directory under `vendor/`, and can be listed at any time with
`composer licenses`.

## PHP (Composer) — runtime

| Package | Licence | Role |
|---|---|---|
| `laravel/framework` | MIT | Application framework |
| `filament/filament` | MIT | Admin panel UI |
| `laravel/tinker` | MIT | REPL / console |
| `bacon/bacon-qr-code` | BSD-2-Clause | QR codes for WireGuard configs |
| `barryvdh/laravel-dompdf` | MIT | Laravel wrapper around Dompdf |
| `dompdf/dompdf` | LGPL-2.1 | PDF rendering (site reports) |
| `league/flysystem-aws-s3-v3` | MIT | S3-compatible offsite backup uploads |

> **Dompdf is LGPL-2.1.** It is used unmodified, as an external library invoked
> through `barryvdh/laravel-dompdf`; vpn-forge does not alter its source. If you
> redistribute a modified Dompdf, the LGPL obligations apply to that component.

## PHP (Composer) — development only

Not shipped to a production install (`composer install --no-dev`): `phpunit/phpunit`
(BSD-3-Clause), `mockery/mockery` (BSD-3-Clause), `fakerphp/faker` (MIT),
`laravel/pint` (MIT), `laravel/pail` (MIT), `nunomaduro/collision` (MIT).

## JavaScript (npm)

The frontend is built at development time and the output is committed under
`public/build`; a production server installs no Node packages.

| Package | Licence | Role |
|---|---|---|
| `gsap` | **GreenSock Standard "No Charge" License** | Panel motion layer |
| `tailwindcss`, `@tailwindcss/vite` | MIT | CSS framework / build plugin |
| `vite`, `laravel-vite-plugin` | MIT | Asset bundler |
| `concurrently` | MIT | Dev script runner |

> **GSAP is not MIT.** It ships under GreenSock's own Standard License, which is
> free for the great majority of uses (including a self-hosted panel like this)
> but is not an OSI-approved open-source licence. Review
> <https://gsap.com/community/standard-license/> before using vpn-forge in a
> context GSAP's terms would not cover, or remove the motion layer.

## Go — capture agent

| Module | Licence |
|---|---|
| `github.com/google/gopacket` | BSD-3-Clause |
| `github.com/go-sql-driver/mysql` | MPL-2.0 |
| `gopkg.in/yaml.v3` | MIT and Apache-2.0 |
| `filippo.io/edwards25519` | BSD-3-Clause |
| `golang.org/x/sys` | BSD-3-Clause |

## System components (installed, not bundled)

The installer pulls these from the Ubuntu archive; they are separate programs
vpn-forge drives, not code it redistributes: nginx (BSD-2-Clause), MariaDB
(GPL-2.0 server / LGPL client), PHP (PHP License 3.01), WireGuard tools
(GPL-2.0), OpenVPN (GPL-2.0), easy-rsa (GPL-2.0), dnsmasq (GPL-2.0), iptables
(GPL-2.0), Redis (BSD / RSALv2+SSPL depending on version).

All trademarks are the property of their respective owners. Listing a component
here does not imply its authors endorse vpn-forge.
