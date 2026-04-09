# Changelog

## [1.4.0](https://github.com/webmappsrl/forestas/compare/v1.3.0...v1.4.0) (2026-04-09)


### Features

* **agents:** ✨ add nova-resource-documenter and test-writer agents ([259cda0](https://github.com/webmappsrl/forestas/commit/259cda0e0f52631d78d738fca15e51507ae5559c))
* **branding:** 🎨 update Nova branding with new logo OC:7492 ([#1](https://github.com/webmappsrl/forestas/issues/1)) ([17ff515](https://github.com/webmappsrl/forestas/commit/17ff5156c18f2614efdb55eb9db61fd82c32f0b4))
* **command:** ✨ enhance reset command with Horizon process termination and Redis flush ([774d7a2](https://github.com/webmappsrl/forestas/commit/774d7a299b0575e463f5e280015adaaaf9d5f8fa))
* **config:** ✨ add default supervisor configurations to horizon.php ([dacaa37](https://github.com/webmappsrl/forestas/commit/dacaa3703f15a0c4a7032272dc1bdc900282f3e3))
* **config:** ✨ add horizon configuration for staging environment ([8173108](https://github.com/webmappsrl/forestas/commit/81731080d3e990f3f78533895adc7d54d6f2bfc6))
* **config:** ✨ add new environment settings for Horizon ([dd7f6a8](https://github.com/webmappsrl/forestas/commit/dd7f6a8f80a15b49575b89c0c02ddca17d285cae))
* **config:** ✨ add new supervisor configuration for AWS environment ([24aba01](https://github.com/webmappsrl/forestas/commit/24aba018633d2b1f7c13f06405fcac2a6f609aa8))
* **config:** ✨ add new supervisor-dem configuration ([d7e5931](https://github.com/webmappsrl/forestas/commit/d7e5931a78f03366545fc2ce80da5cc3dfc7cff7))
* **database:** ✨ add cascade delete to taxonomy_whereables foreign key ([96bf8a9](https://github.com/webmappsrl/forestas/commit/96bf8a926e7ac84ba05d76974bc03e50b5f43453))
* **database:** ✨ add migration scripts for new database structure ([1bfd010](https://github.com/webmappsrl/forestas/commit/1bfd0105a46b091ec562aaa83000c8c5dff1c123))
* **database:** ✨ add taxonomy migrations ([d5f6c2f](https://github.com/webmappsrl/forestas/commit/d5f6c2f73512587ff1ba61123153f81e5ba3cad9))
* **dto:** ✨ implement typed DTOs for API responses ([b3d3bd9](https://github.com/webmappsrl/forestas/commit/b3d3bd976d4443ffc04b443c6a73cb81e335f1d1))
* **import:** ✨ add --reset option for sardegnasentieri import ([819090d](https://github.com/webmappsrl/forestas/commit/819090de910e0541becacf2481a73b612e5a69f0))
* **import:** ✨ add app selection and user assignment to taxonomy import ([577ca06](https://github.com/webmappsrl/forestas/commit/577ca0630c4d663c35e33e8f03de4433e9f84823))
* **import:** ✨ add icon fallback map for identifier resolution ([50e9bd6](https://github.com/webmappsrl/forestas/commit/50e9bd6b00c947e28b57f18971bb65272a8c73cf))
* **import:** ✨ add taxonomy import action and service ([d38d0b9](https://github.com/webmappsrl/forestas/commit/d38d0b91410a063bfabcb722e60e89573965d7b9))
* **import:** ✨ enhance import logging and scheduling ([c707f56](https://github.com/webmappsrl/forestas/commit/c707f56a37a05bf9bb4d1d4d1d92f46313f42294))
* **import:** ✨ implement media sync for POIs and tracks ([f842b23](https://github.com/webmappsrl/forestas/commit/f842b2339a41813b46b6c77b71f8b113c8a08832))
* **import:** 🚀 add Sardegna Sentieri data import functionality ([44a46ef](https://github.com/webmappsrl/forestas/commit/44a46efebdc5da2188a6c979301bed72e58c9186))
* **import:** convert ForestasPoiData list fields to assoc arrays for Nova KeyValue ([3b63b62](https://github.com/webmappsrl/forestas/commit/3b63b62a2b4cb7a54cc8813d9441a2977816bc7b))
* **import:** convert ForestasTrackData list fields to assoc arrays for Nova KeyValue ([3b63b62](https://github.com/webmappsrl/forestas/commit/3b63b62a2b4cb7a54cc8813d9441a2977816bc7b))
* **layer:** 🆕 add "In Home" boolean and AddToConfigHome action ([9ef0936](https://github.com/webmappsrl/forestas/commit/9ef09364b6af64a4ff2313726b6bbbb4b8645470))
* **migration:** ✨ rename theme filters and create app filter layers table ([79289fa](https://github.com/webmappsrl/forestas/commit/79289fa1737a2fa2b08ca9c76633a39fe1f9e882))
* **models:** ✨ add custom translation handling in EcPoi ([12582d5](https://github.com/webmappsrl/forestas/commit/12582d5b1fec12d3b7bf94845001902fb24c9f98))
* **models:** ✨ add media collection registration to Ente ([774d7a2](https://github.com/webmappsrl/forestas/commit/774d7a299b0575e463f5e280015adaaaf9d5f8fa))
* **models:** ✨ add translatable fields to EcPoi and EcTrack ([eaf3d34](https://github.com/webmappsrl/forestas/commit/eaf3d3465454f8ae1fe6ca4a0691053382f2aa29))
* **nova-filters:** ✨ add vocabulary filter for taxonomies in Nova ([0a3527f](https://github.com/webmappsrl/forestas/commit/0a3527ffbf390e8ed4573fc98256ba3a96d29bf8))
* **nova-taxonomies:** ✨ enhance taxonomy resources with vocabulary support ([0a3527f](https://github.com/webmappsrl/forestas/commit/0a3527ffbf390e8ed4573fc98256ba3a96d29bf8))
* **nova:** ✨ add API links card to EcTrack ([7271a30](https://github.com/webmappsrl/forestas/commit/7271a30e24a18384207d2424de6cc68862432259))
* **nova:** ✨ add BelongsToMany relationship for related POIs ([c787662](https://github.com/webmappsrl/forestas/commit/c787662e4580b07862ee97fd786cec04ad9ecac2))
* **nova:** ✨ add Ente resource to Nova service provider ([06e4bf3](https://github.com/webmappsrl/forestas/commit/06e4bf374b94837300bdb19e92e9a6aba398e3d5))
* **nova:** ✨ add feature collection resource and migrations ([4582f82](https://github.com/webmappsrl/forestas/commit/4582f82532910e9b5f9df64eb2719f3baed0ffca))
* **nova:** ✨ enhance field management with additional relationships ([f042c2d](https://github.com/webmappsrl/forestas/commit/f042c2dfb647e3a9a2046cda9adb6a9df7583cb2))
* **nova:** ✨ integrate NovaTabTranslatable and Tiptap for fields ([eaf3d34](https://github.com/webmappsrl/forestas/commit/eaf3d3465454f8ae1fe6ca4a0691053382f2aa29))
* **nova:** add DEM tab to EcTrack Details group ([e1f63e6](https://github.com/webmappsrl/forestas/commit/e1f63e626fe80ca38e0cb39950b08e17d085136a))
* **nova:** add Forestas tab to EcPoi resource ([3b63b62](https://github.com/webmappsrl/forestas/commit/3b63b62a2b4cb7a54cc8813d9441a2977816bc7b))
* **nova:** add Forestas tab to EcTrack resource ([3b63b62](https://github.com/webmappsrl/forestas/commit/3b63b62a2b4cb7a54cc8813d9441a2977816bc7b))
* **reset-command:** ✨ enhance taxonomy and poi deletion logic ([0a3527f](https://github.com/webmappsrl/forestas/commit/0a3527ffbf390e8ed4573fc98256ba3a96d29bf8))
* **seeder:** ✨ add configurable admin password for seeding ([b9fb7a8](https://github.com/webmappsrl/forestas/commit/b9fb7a8cc181a4b0e1f9066c38a73db39983c1c8))
* **taxonomy-where:** add TaxonomyWhere Nova resource and menu integration ([2121eb1](https://github.com/webmappsrl/forestas/commit/2121eb1155119c29329cf305368ec32a8314db19))
* **taxonomy:** 🆕 add filters and CreateLayer action for TaxonomyWhere ([9ef0936](https://github.com/webmappsrl/forestas/commit/9ef09364b6af64a4ff2313726b6bbbb4b8645470))


### Bug Fixes

* **api:** 🐛 ensure `come_arrivare` is formatted correctly ([0a3527f](https://github.com/webmappsrl/forestas/commit/0a3527ffbf390e8ed4573fc98256ba3a96d29bf8))
* **api:** 🐛 normalize taxonomy id lists ([b9fb7a8](https://github.com/webmappsrl/forestas/commit/b9fb7a8cc181a4b0e1f9066c38a73db39983c1c8))
* **migrations:** 🐛 remove unnecessary overlays_label field from apps table ([d3a8f5a](https://github.com/webmappsrl/forestas/commit/d3a8f5af9d92596a2686fbd4df61abee6e29d93d))
* **nova:** add strict_types and document wm-package Tab dependency in EcPoi/EcTrack ([3b63b62](https://github.com/webmappsrl/forestas/commit/3b63b62a2b4cb7a54cc8813d9441a2977816bc7b))
* **nova:** filter TabsGroup (not Tab) when rebuilding Details group in EcPoi/EcTrack ([2e5cc78](https://github.com/webmappsrl/forestas/commit/2e5cc7885d9db78bb829b149c0cad2cd03ce0564))
* **nova:** merge Forestas tab inside existing Details group for EcPoi ([3b63b62](https://github.com/webmappsrl/forestas/commit/3b63b62a2b4cb7a54cc8813d9441a2977816bc7b))
* **parsing:** 🐛 handle GPX namespace issues during parsing ([b3d3bd9](https://github.com/webmappsrl/forestas/commit/b3d3bd976d4443ffc04b443c6a73cb81e335f1d1))
* **schedule:** 🐛 correct scheduling typo and add environment-based logic ([3d5ecb8](https://github.com/webmappsrl/forestas/commit/3d5ecb8787f91acdd5085574cb20e0bcd7d4ea06))
* **test:** remove unused PHPUnit Test attribute import ([3b63b62](https://github.com/webmappsrl/forestas/commit/3b63b62a2b4cb7a54cc8813d9441a2977816bc7b))


### Miscellaneous Chores

* **gitignore:** 🔧 adjust .vscode ignore rules and add settings ([bb66d63](https://github.com/webmappsrl/forestas/commit/bb66d638b13d64bf1be4301666de1c50aa05bcc6))
* **gitignore:** 🔧 update storage ignore pattern ([0bdb305](https://github.com/webmappsrl/forestas/commit/0bdb30583ee263f3223d5c954753bcc45255d45c))
* **localization:** 🌐 add English and Italian translations for taxonomy labels ([0a3527f](https://github.com/webmappsrl/forestas/commit/0a3527ffbf390e8ed4573fc98256ba3a96d29bf8))
* **project:** 🔧 update documentation and final verification checklist ([b3d3bd9](https://github.com/webmappsrl/forestas/commit/b3d3bd976d4443ffc04b443c6a73cb81e335f1d1))

## [1.3.0](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.2.1...v1.3.0) (2026-03-17)


### Features

* add local.compose.yml for standalone local development ([40e2117](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/40e21175ba80d779e5a70cb14d359b18ec51a9b1))
* align boilerplate with camminiditalia improvements ([b3400c4](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/b3400c423212637755e15b8b6127b282810e446a))
* **nova:** ✨ add Media resource and update NovaServiceProvider ([7516557](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/75165577e8fbccbe20d19e1ee81659242309f147))


### Bug Fixes

* add scout-init service to local.compose.yml ([7d7c62f](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/7d7c62fcbfc66bd9a285083154bd79ebe98c10ca))
* align all container names to dash convention ([fc6a6fb](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/fc6a6fb6f66f0fb8b8215a05022eb4aab47831be))
* update README to Laravel 12 and align deploy_prod.sh ([e594d61](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/e594d61f231827b227b2c7ec14b0c02329cad731))


### Miscellaneous Chores

* **gitignore:** ➕ add .gitignore to storage/debugbar directory ([c232f51](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/c232f51b2be3fe724de132e593f5762927ff0dfe))
* **gitignore:** ➕ add nova directory to ignore list ([f79141f](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/f79141fffdd28ba3a0c83a4c7e3a05e86052dba4))
* **scripts:** 🚀 add comprehensive installation script ([7516557](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/75165577e8fbccbe20d19e1ee81659242309f147))

## [1.2.1](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.2.0...v1.2.1) (2025-04-23)


### Bug Fixes

* dependencies ([5722f55](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/5722f55ccca693860da3bbc33d0229fa47553a07))
* jwt ([0a32edc](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/0a32edca820bbfae95b7036f7979c7eb92ae4799))
* providers ([f42feaa](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/f42feaa9a5493b7aa3a9b414c93702ddedd523dc))
* providers ([800cae6](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/800cae6e1d534e0f71ede4bd4688b042e08806a4))
* queue connection ([c418307](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/c418307119d72a74cb7a821c17b38e798a8450c7))

## [1.2.0](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.1.3...v1.2.0) (2025-04-23)


### Features

* redirect to nova dashboard ([381f238](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/381f238faa6bedbd1c47d5ae436f82cbef88c4bd))


### Bug Fixes

* user model ([08776ab](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/08776ab7755809b1fdff8b9cdbf016058da96866))
* user model ([77afed0](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/77afed0f71ad771cd2f6cb919ca480547028846c))

## [1.1.3](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.1.2...v1.1.3) (2025-04-09)


### Bug Fixes

* Update composer.json ([0bf706c](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/0bf706c04764af041ad9a408a84edc769f418420))
* Update composer.json ([c371dc2](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/c371dc2a8de7b513c96603fa11f9af5242cfe4af))

## [1.1.2](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.1.1...v1.1.2) (2025-03-17)


### Miscellaneous Chores

* Update .gitignore ([003b369](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/003b369248b0533821bd33a3d0c115e56c439042))
* Update .gitignore ([38db055](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/38db0555894764d4259308cea2a943aa5c7bb04d))

## [1.1.1](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.1.0...v1.1.1) (2025-03-11)


### Bug Fixes

* docker compose elasticsearch oc:4975 ([6b2b549](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/6b2b549e4786fefb6f4b838f87be6c2f46fcef4d))
* Update develop.compose.yml ([8fc9f31](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/8fc9f311340b00d374cecac912d1d2005ff5ffb4))
* Update develop.compose.yml ([002d2a6](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/002d2a699cf6ec1e719d7ddb4ca972797f52fcdb))


### Miscellaneous Chores

* Update .env-example ([1c21546](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/1c215463a1e59aa67a50156baaa7f6502b8da6a3))
* Update .env-example ([fec7c1c](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/fec7c1c2d193d797aa07a5daaf4339c381ff2fd8))
* Update init-docker.sh ([0714a88](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/0714a88e2e2b2ebcee5880dbf99287f9d0aff414))
* Update init-docker.sh ([d1edddb](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/d1edddb6c0218c35aaf5c3757f2a398970851934))

## [1.1.0](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.0.1...v1.1.0) (2025-02-27)


### Features

* increase post_max_size in php.ini ([59332d6](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/59332d6912a835890c2681552f4a19b6b6388b63))

## [1.0.1](https://github.com/webmappsrl/laravel-postgis-boilerplate/compare/v1.0.0...v1.0.1) (2025-02-20)


### Bug Fixes

* pg_dump version ([e440add](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/e440add260da5c7e404e14894a62d9a78cb4cea9))
* update wm-package version ([4c6d152](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/4c6d1524310f9ff05c7c5fa15820687a4db8e9ec))

## 1.0.0 (2025-02-12)


### ⚠ BREAKING CHANGES

* laravel 11 and new wm-package
* updated to laravel 11

### Features

* add GitHub Actions workflows for CI/CD ([0f81bff](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/0f81bff0aa3cd6c4535f56f3b101b7e0497e0703))
* configured log viewer ([1f0c069](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/1f0c06991956553a174bdb377e0b23bb92c7c86f))
* enable code coverage xdebug feature on xdebug.ini oc: 4354 ([1a24374](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/1a2437416f22adab474f6e74de634ba40774bfe8))
* laravel 11 and new wm-package ([7bd7913](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/7bd79139340c25bbbb53ddf1bf51ba1466428d8a))
* update wm-package ([f40e772](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/f40e772befbc22931033f647ab916e1ce7a9fd21))
* updated compose and readme ([acb0111](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/acb01115edd598d8111b9cb4c54d7b46997ebe44))
* updated to laravel 11 ([87715ca](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/87715caa106cf25f041e6c06befb10f8531ee3b1))


### Bug Fixes

* github actions ([6f61f43](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/6f61f43a64d6acb6dff489b13420cc951845c466))
* init-docker script ([6366ccc](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/6366ccc327b37aadd839048cd92b0c1a4583a71d))
* phpstan error ([d181a54](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/d181a54283eafcffeb411ca832084c5bd5bbec1f))
* remove --build flag from docker compose commands ([251e0aa](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/251e0aa88fa74267061f35f7332946fb702ee7ac))
* update Nova service provider imports and menu authorization ([1bf75d6](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/1bf75d68fdc74175539cd0805906f973ca502479))
* update README and init-docker script with minor improvements ([91ea0a4](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/91ea0a4c843eb5a0e4d234bb828504b89799aa8d))


### Miscellaneous Chores

* add git submodule initialization to deployment scripts ([ebe6955](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/ebe6955221137eb694ccd4581d4f0e3281b015a9))
* installed horizon and log viewer packages ([262ea7a](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/262ea7a8c48221b749e05fba1430a3ee46842388))
* supervisor docker configuration ([4fe19fa](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/4fe19fa3333074e717673ce067ae7201eef7e0a1))
* updated dependencies ([25587f0](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/25587f032339379bd7e24b8c4ea38835ee54c677))
* updated docker compose and dockerFile ([d2f8ab1](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/d2f8ab1ebfd62a920d3ae8f69efc48659429ae59))
* updated laravel horizon and supervisor docker conf ([15f87e9](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/15f87e93374ee9c765ff849aaf91d5cb7e8491ad))
* updated to php 8.3 ([617f65b](https://github.com/webmappsrl/laravel-postgis-boilerplate/commit/617f65b96a52207b0d38aa1157ee99be6462aad6))
