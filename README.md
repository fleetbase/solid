<p align="center">
    <a href="https://github.com/fleetbase/solid">
        <img src="https://github.com/fleetbase/solid/assets/816371/aeff9036-6807-4e0a-a859-1dd5bee49c02" width="200" height="200" alt="Fleetbase Solid" />
    </a>
</p>

<h1 align="center">Fleetbase Solid</h1>

<p align="center">
    Solid Protocol extension to store and share Fleetbase data in Solid pods.
</p>

<p align="center">
    <a href="https://github.com/fleetbase/solid/blob/main/LICENSE.md"><img src="https://img.shields.io/badge/license-AGPL--3.0--or--later-blue.svg" alt="License: AGPL-3.0-or-later" /></a>
    <a href="https://github.com/fleetbase/solid/actions/workflows/server.yml"><img src="https://github.com/fleetbase/solid/actions/workflows/server.yml/badge.svg" alt="PHP CI" /></a>
    <a href="https://github.com/fleetbase/solid/actions/workflows/ember.yml"><img src="https://github.com/fleetbase/solid/actions/workflows/ember.yml/badge.svg" alt="Ember CI" /></a>
    <a href="https://codecov.io/gh/fleetbase/solid"><img src="https://codecov.io/gh/fleetbase/solid/branch/main/graph/badge.svg" alt="Coverage" /></a>
    <a href="https://www.npmjs.com/package/@fleetbase/solid-engine"><img src="https://img.shields.io/npm/v/@fleetbase/solid-engine.svg" alt="npm version" /></a>
    <img src="https://img.shields.io/badge/php-%5E8.2-777BB4.svg" alt="PHP ^8.2" />
    <img src="https://img.shields.io/badge/node-22.x-339933.svg" alt="Node 22.x" />
</p>

## Overview

[Solid](https://solidproject.org) is a specification, led by Sir Tim Berners-Lee, in which people and organizations keep their data in online data stores ("pods") that they control, and grant applications access to it through a WebID and Solid-OIDC. Data in a pod is Linked Data (RDF), so any Solid-compatible application can read it rather than it being locked into one platform.

This extension connects Fleetbase to a Solid server. Each Fleetbase user links a Solid WebID to their Fleetbase organization, browses and manages the contents of their pod from the Fleetbase console, and exports Fleet-Ops records (vehicles, drivers, contacts, and orders) into the pod as Turtle documents.

Solid ships as both a Laravel package and an Ember engine. The backend package provides the Solid client, the Solid-OIDC handshake, the pod and ACL services, the Fleet-Ops export, routes, and migrations. The frontend engine provides the Solid console screens and the admin server configuration panel.

## Contents

- [Features](#features)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Development](#development)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [Security](#security)
- [Funding](#funding)
- [License](#license)

## Features

### Solid identity

- Link a Fleetbase user and organization to a Solid WebID through the Solid-OIDC authorization code flow.
- Connection status, WebID, and profile details read from the user's Solid profile document.
- Sign out of Solid, which clears the stored tokens and the dynamic client registration.
- One Solid identity per user per organization, stored in the `solid_identities` table.

### Pod data

- Browse the root of the user's pod and open folders (LDP containers).
- Create folders with the Solid Protocol `POST` and `Slug` header.
- Delete files and folders.
- Web Access Control (WAC) checks before writing. When the requested location is not writable, the response lists the writable storage locations from the user's profile.

### Fleet-Ops export

- Import Fleet-Ops vehicles, drivers, contacts, and orders for the current organization into the pod.
- Each record is written as a Turtle document at `<pod>/<type>/<public_id>.ttl`, using the `http://fleetbase.io/ns/` vocabulary, inside a container created for each resource type.
- Up to 100 records per resource type per import.

### Administration

- A **Solid Protocol > Solid Server Config** panel in the Fleetbase admin to set the Solid server host, port, and TLS. It is saved as a system setting and overrides the environment defaults.
- A **Solid** item in the console header that opens the extension.

## Architecture

Solid is split into two distributable packages:

| Package | Runtime | Description |
| --- | --- | --- |
| `fleetbase/solid-api` | Laravel / PHP | Solid client, Solid-OIDC handshake, `SolidIdentity` model, pod, ACL and resource sync services, routes, and migrations. |
| [`@fleetbase/solid-engine`](https://www.npmjs.com/package/@fleetbase/solid-engine) | Ember | Fleetbase console engine for the Solid home, account, and data screens, and the admin server configuration panel. |

`SolidServiceProvider` requires both `fleetbase/core-api` and `fleetbase/fleetops-api` and will not load without them.

### Backend

| Path | Purpose |
| --- | --- |
| `server/src/Client/SolidClient.php` | HTTP client for the Solid server. Resolves the server from the admin setting first, then `config('solid.server')`, and sends authenticated requests for a `SolidIdentity`. |
| `server/src/Client/OpenIDConnectClient.php`, `server/src/Auth/` | Solid-OIDC relying party: provider discovery, dynamic client registration, PKCE, DPoP-bound tokens, and ID token verification against the provider's JWKS. |
| `server/src/Models/SolidIdentity.php` | Links a user and company to a Solid identity and holds the token response and the verified ID token claims. |
| `server/src/Services/PodService.php` | Profile parsing, pod URL resolution from the WebID, container listing, folder creation, and deletion. |
| `server/src/Services/AclService.php` | WAC checks and ACL document generation for the pod and its folders. |
| `server/src/Services/ResourceSyncService.php` | Converts Fleet-Ops records to Turtle and writes them to the pod. |
| `server/config/solid.php` | Solid server and Solid-OIDC settings. |
| `server/migrations/` | The `solid_identities` table. |

Backend routes are mounted under the `solid` prefix with the internal prefix `int`.

| Route | Authentication | Purpose |
| --- | --- | --- |
| `GET /solid/int/v1/authenticate/{identifier}` | Public | Starts the Solid-OIDC flow and redirects to the identity provider. |
| `ANY /solid/int/v1/oidc/complete-registration/{identifier}` | Public | Authorization callback. Exchanges the code for tokens and redirects back to the console. |
| `GET /solid/int/v1/request-authentication`, `GET /solid/int/v1/authentication-status`, `POST /solid/int/v1/logout` | Fleetbase session | Begin sign-in, read connection status, and sign out. |
| `GET /solid/int/v1/account` | Fleetbase session | The Solid server's account index for the current identity. |
| `GET /solid/int/v1/data`, `GET /solid/int/v1/data/folder/{slug}` | Fleetbase session | Pod root and folder contents. |
| `POST /solid/int/v1/data/folder`, `DELETE /solid/int/v1/data/{type}/{slug}` | Fleetbase session | Create a folder; delete a file or folder. |
| `POST /solid/int/v1/data/import` | Fleetbase session | Import Fleet-Ops resources (`vehicles`, `drivers`, `contacts`, `orders`) into the pod. |
| `GET /solid/int/v1/server-config`, `POST /solid/int/v1/server-config` | Fleetbase admin | Read and save the Solid server configuration. |

### Frontend

The Ember engine mounts at the Fleetbase extension route `solid-protocol` and provides these routes:

| Route | Purpose |
| --- | --- |
| `home` | Getting started, connection status, and **Connect to Solid**. |
| `account` | The user's account details alongside the Solid identity. |
| `data` | The pod root, with **New Folder** and **Import Resources**. |
| `data/content` | The contents of a folder in the pod. |

## Requirements

- PHP `^8.2` with the `json` and `openssl` extensions
- Composer
- Fleetbase Core API `^2.0` (`fleetbase/core-api`)
- Fleetbase Fleet-Ops API `^0.7.0` (`fleetbase/fleetops-api`)
- The Laravel 12 Fleetbase API (fleetbase/fleetbase v0.8.0 or later)
- Redis, which holds in-flight Solid-OIDC authorization state
- A Solid server that supports Solid-OIDC, such as the Community Solid Server
- Node.js 22.x
- pnpm

Laravel 10 installations stay on the v0.0.8 line of this extension.

## Installation

Install Solid from the Extensions section of the Fleetbase console, then run migrations through your normal Fleetbase deployment flow:

```bash
php artisan migrate
```

For package-level installation of the Ember engine:

```bash
pnpm install @fleetbase/solid-engine
```

The backend package is `fleetbase/solid-api`.

After installation:

1. Open **Admin > Solid Protocol > Solid Server Config** and set the host, port, and TLS setting of your Solid server.
2. Open **Solid** from the console header and choose **Connect to Solid** to link your WebID.

## Configuration

Solid reads its defaults from `server/config/solid.php`. The Solid server settings can be overridden per instance from the admin panel. The Solid-OIDC settings are read only from the environment.

| Variable | Default | Purpose |
| --- | --- | --- |
| `SOLID_HOST` | `http://solid` | Solid server host. |
| `SOLID_PORT` | `3000` | Solid server port. |
| `SOLID_SECURE` | `false` | Use HTTPS for the Solid server. |
| `SOLID_OIDC_ISSUER` | The Solid server URL | Where to fetch `.well-known/openid-configuration` from, when the identity provider is reached at a different address than the data API. |
| `SOLID_OIDC_VERIFY_TLS` | `true`, unless `APP_ENV` is `local` or `development` | TLS verification for discovery, registration, token, and JWKS requests. Set it to `false` only for a development Solid server with a self-signed certificate. |
| `SOLID_OIDC_STATE_TTL` | `600` | Seconds an in-flight authorization request stays valid. |
| `SOLID_OIDC_CLIENT_CREDENTIALS_TTL` | `0` | Seconds a dynamic client registration is kept. `0` keeps it until sign-out. |
| `SOLID_OIDC_JWKS_CACHE_TTL` | `300` | Seconds the provider's JWKS is cached. |
| `SOLID_OIDC_DISCOVERY_CACHE_TTL` | `300` | Seconds the provider's discovery document is cached. |
| `SOLID_OIDC_LEEWAY` | `60` | Clock skew, in seconds, allowed on ID token `exp`, `iat`, and `nbf`. |
| `SOLID_OIDC_REQUIRE_NONCE` | `true` | Reject an ID token that does not echo the request nonce. |
| `SOLID_OIDC_TIMEOUT` | `15` | Timeout, in seconds, for Solid-OIDC HTTP requests. |
| `SOLID_OIDC_KEY_DISK` | `local` | Filesystem disk that holds each identity's DPoP private key. It must not be reachable from the internet. |

## Development

### Fleetbase workspace linking

When working on Solid inside a full Fleetbase checkout, use Fleetbase's package linker from the repository root instead of hand-editing `console/package.json`, `api/composer.json`, or `console/pnpm-workspace.yaml`.

Install the linker once from the Fleetbase repository root:

```bash
npm link
```

Enable Solid as a local development package, with this repository checked out at `packages/solid`:

```bash
flb-package-linker enable solid
flb-package-linker install solid
```

Use `--install` to let the linker run the required package-manager commands immediately:

```bash
flb-package-linker enable solid --install
```

Check link state with:

```bash
flb-package-linker status
flb-package-linker doctor
```

See the [Fleetbase development setup guide](https://www.fleetbase.io/docs/platform/quickstart/development-setup) for Docker mounts, local Ember dev server setup, package-linker details, and unlink/reset commands. Fleetbase runs Laravel Octane, so reload the API worker after PHP changes:

```bash
docker compose exec application php artisan octane:reload
```

### Package-level development

Install dependencies:

```bash
composer install
pnpm install
```

Composer installs into `server_vendor/` rather than `vendor/`.

Run the Ember engine locally:

```bash
pnpm start
```

Frontend checks:

```bash
pnpm lint
pnpm test
pnpm test:ember
pnpm build
```

Backend checks:

```bash
composer test:lint
composer test:types
composer test:unit
composer test
```

`composer test` runs the PHP CS Fixer dry run, PHPStan, and the Pest suite. `composer lint` runs PHP CS Fixer in fix mode and rewrites files.

Coverage:

```bash
composer test:coverage
composer coverage:baseline
```

## Documentation

- [Fleetbase documentation](https://www.fleetbase.io/docs)
- [Fleetbase development setup](https://www.fleetbase.io/docs/platform/quickstart/development-setup)
- [Solid Project](https://solidproject.org)
- [Release notes](RELEASE.md)

## Contributing

Contributions are welcome. Please read the [contributing guide](CONTRIBUTING.md) before opening a pull request.

For local changes, keep frontend and backend checks focused on the area you touched and include relevant test output in your pull request.

## Security

Please do not report security issues in public GitHub issues. Contact Fleetbase at [hello@fleetbase.io](mailto:hello@fleetbase.io) with details so the team can coordinate a responsible fix.

## Funding

This project is funded through [NGI0 Entrust](https://nlnet.nl/entrust), a fund established by [NLnet](https://nlnet.nl) with financial support from the European Commission's [Next Generation Internet](https://ngi.eu) program. Learn more at the [NLnet project page](https://nlnet.nl/project/Fleetbase-Solid).

[<img src="https://nlnet.nl/logo/banner.png" alt="NLnet foundation logo" width="20%" />](https://nlnet.nl)

## License

Fleetbase Solid is open-source software licensed under the [AGPL-3.0-or-later](LICENSE.md).
