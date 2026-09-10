# Project Structure

Map of the repository and the conventions that keep it consistent. See
[Architecture](architecture.md) for the rules that shape this layout.

## Top-level view

```
crud-skeleton/
├── src/                    # All application code (PSR-4 namespace App\)
├── config/                 # Symfony + module routing & service configuration
├── migrations/             # Doctrine versioned migrations (DoctrineMigrations\)
├── tests/                  # PHPUnit tests, grouped by execution tier
├── docs/                   # Documentation site (MkDocs) + archives
├── scripts/                # Build, translation, and smoke/stress tooling
├── public/                 # Web root (index.php, assets)
├── var/                    # Cache, logs, JWT keys, test DBs (git-ignored)
├── translations/           # Symfony translation catalogues
├── templates/              # Twig templates (dev/profiler pages)
├── assets/                 # Asset-map sources (js/css imports)
├── docker/                 # Container files: entrypoint.sh, nginx config
├── compose.yaml            # Base service stack
├── compose.override.yaml   # Development overlay (dev flags, source bind, ports)
├── compose.prod.yaml       # Production overlay
├── Dockerfile              # PHP-FPM image for app/worker/scheduler
├── mkdocs.yml              # Docs site navigation (English)
├── mkdocs-en.yml / mkdocs-zh.yml   # Bilingual docs builds
├── phpunit.dist.xml        # PHPUnit configuration
├── phpstan.neon            # Static analysis configuration (Level 8)
├── rector.php / rector-types.php   # Rector rulesets
└── .env*                   # Environment files (.env, .env.example, .env.test, ...)
```

## `src/` — application modules

Each business module is a vertical slice. `Core` is the framework layer; all other
modules plug into it.

```
src/
├── Kernel.php                      # Application kernel (no App\Kernel, lives in src/)
├── Core/                           # Framework core — depends on nothing business
│   ├── CoreBundle.php              # Bundle class registered in config/bundles.php
│   ├── Controller/
│   │   ├── RestController.php      # Base REST controller (success/warning, pagination)
│   │   ├── DefaultController.php, HealthController.php, MetricsController.php
│   │   └── System/                 # /system/* introspection (Entity, Router)
│   ├── View/                       # API view mixins (List/Detail/Create/Update/Delete/…)
│   ├── Service/
│   │   ├── BaseService.php         # Generic CRUD service base + ServiceLocator glue
│   │   ├── BaseServiceInterface.php
│   │   ├── Concern/                # Infrastructure / ReadList / Mutation traits
│   │   ├── ExpressionService.php   # @filter/@sort/@dql/@order/@select engine
│   │   └── QueryBuilderFactory.php, DefaultServiceLocator.php
│   ├── Parser/                     # ExpressionDqlParser, ExpressionQueryBuilderAssembler
│   ├── Serializer/                 # Normalizers, circular-reference handling
│   ├── EventListener/              # AccessLog, ExceptionInterceptor, RateLimit, Locale, …
│   ├── Validator/                  # JsonSchemaValidator + JsonSchemaViolation (justinrainbow/json-schema)
│   ├── Doctrine/Dql/               # (reserved) custom DQL functions
│   ├── Metrics/, Exception/, Utils/  # MetricsRegistry, UUID, Math, RsaClient, …
│   └── Resources/config/services.yaml
│
├── Common/                         # CMS module
│   ├── Controller/{App,Manage}/    # Category, Tag, Content, Comment, Page, Media, Picture, Setting
│   ├── Controller/Public/MediaController.php
│   ├── Entity/                     # Category, Tag, Content, Comment, Page, Media, Picture, Setting
│   ├── Repository/                 # …Repository.php + …RepositoryInterface.php
│   └── Service/                    # …Service.php + …ServiceInterface.php per entity
│
├── Identity/                       # Auth & accounts
│   ├── Command/CreateUserCommand.php        # app:identity:user:create
│   ├── Controller/{Auth, Otp}.php, Controller/{App,Manage}/…
│   ├── Security/                   # JwtAuthenticator, TokenManager (rotation)
│   ├── Service/                    # UserService, ProfileService, OtpService (+ storages)
│   ├── Sms/                        # AliyunSmsProvider, SmsProviderInterface
│   ├── Entity/                     # User, Profile, RefreshToken
│   ├── Repository/, EventListener/
│   └── Resources/config/services_identity.yaml
│
├── Trade/                          # E-commerce
│   ├── Controller/{App,Manage}/    # Product, Specification, Order (currency from Store via X-Store-Code), OrderItem
│   ├── Service/
│   │   ├── OrderService.php (currency from StoreContext), ProductService.php, SpecificationService.php
│   │   ├── Pricing/                # BasePrice → Quantity → TotalAggregator pipeline (currency validated)
│   │   └── TradeOutboxService.php
│   ├── Entity/                     # Product (store-private), Specification, Order (currency varchar(32), REWARD_POINT), OrderItem, TradeOutboxMessage
│   ├── DTO/StoreContext.php        # StoreContext with currency snapshot (code, uuid, name, channel, currency)
│   ├── Message/ + MessageHandler/  # TradeOrderCreated/Cancelled, StoreOrderAccepted/Rejected
│   ├── Event/ + EventListener/     # Order*Event, OrderInvoiceListener, OrderWorkflowListener
│   └── Command/PublishOutboxCommand.php
│
├── Store/                          # Multi-store operations
│   ├── Controller/{App,Manage,Staff}/  # App: Store (public) + Membership self-join (`POST /app/stores/{uuid}/membership`); Manage: Store CRUD + members; Staff: Store-scoped ops
│   ├── Service/                    # StoreService, StoreOrderService, StoreOutboxService, StoreContextResolver (X-Store-Code → StoreContext with currency)
│   ├── Entity/                     # Store (currency varchar(32) DEFAULT CNY, REWARD_POINT for points mall), Membership, StoreOrder (currency), StoreOutboxMessage, …
│   ├── Resources/JsonSchema/       # StoreAddress.json, StoreContact.json (subTitle 1..100 + tags array 1..30×20), StoreSettings.json
│   ├── MessageHandler/             # Reservation*, TradeOrder* handlers
│   └── Command/PublishOutboxCommand.php
│
├── Inventory/                      # Stock & reservations
│   ├── Controller/Manage/          # Material, Recipe, Stock
│   ├── Service/                    # InventoryService, StockService, MaterialService, Quantity
│   ├── Entity/                     # Stock, Reservation, ReservationLine, LedgerEntry, …
│   ├── Message/ + MessageHandler/  # ReservationRequested/Confirmed/Rejected/Released/…
│   └── Command/                    # PublishOutbox, ReleaseExpiredReservations
│
├── Payment/                        # Invoice lifecycle & gateways
│   ├── Controller/{App,Manage,Webhook}/  # Invoice, PaymentNotify
│   ├── Service/
│   │   ├── InvoiceService.php      # State machine: pending → paid/refunded/failed/cancelled
│   │   ├── Gateway/                # MockGateway + PaymentGatewayInterface
│   │   └── Adjustment/             # PaymentAdjustmentRegistry (wallets etc.)
│   ├── DTO/, Event/, Exception/, Entity/Invoice.php, Repository/
│   └── Resources/config/services_payment.yaml   # payment.gateway tag
│
├── Wallet/                         # Balances & transfers
│   ├── Controller/{App,Manage}/    # Wallet, Transaction, Voucher, PaymentDeduction
│   ├── Service/
│   │   ├── WalletService.php, TransactionService.php, ReconciliationService.php
│   │   ├── Deposit/  Withdraw/  Transfer/  Payment/  (provider registries)
│   │   └── Concern/WrapInTransactionTrait.php
│   ├── Entity/                     # Wallet (versioned, optimistic lock), Transaction, Voucher, …
│   ├── DTO/, Exception/
│   └── Integration/Settlement/     # WalletSettlementVoucherPort
│
├── Promotion/                      # Promotions & pricing effects
│   ├── Controller/{App,Manage}/    # Promotion, PromotionTemplate
│   ├── Service/
│   │   ├── PromotionService.php, PromotionTemplateService.php
│   │   ├── PromotionCalculator.php # tagged trade.price_calculator (priority 60)
│   │   └── Dsl/                    # Lexer, Parser, Evaluator, AST for rule expressions
│   ├── Strategy/                   # Discount, FullReduction, Gift, Tiered, MemberDiscount, …
│   └── Entity/                     # Promotion, PromotionTemplate
│
├── Settlement/                     # Rule-driven allocation & finality
│   ├── Controller/Manage/          # SettlementRule, Plan, Allocation, Outbox, …
│   ├── Service/
│   │   ├── SettlementService.php, SettlementRuleEngine.php
│   │   └── Money/                  # QuantumAmount (brick/math, scale 18), AllocationRoundingService
│   ├── Port/ + Integration/        # SettlementVoucherPort, Clock; in-memory/fixture impls
│   ├── Contract/                   # DTO contracts: AllocationProposal, ComputedAllocation, …
│   ├── Context/                    # SettlementContextResolverRegistry
│   ├── Message/ + MessageHandler/  # FundingConfirmed, AllocationPosting
│   └── Command/                    # PublishOutbox, RequeueDuePosting
│
├── Storage/                        # Media storage abstraction
│   └── Service/                    # MediaStorageInterface, MediaStorageRegistry, LocalStorage, QiniuStorage
│
└── Wechat/                         # WeChat login + Pay
    ├── Controller/                 # LoginController, Controller/{App,Manage}/WechatUser
    ├── Service/                    # WechatAuthService, WechatService, WechatUserService
    ├── Service/Payment/WechatPayGateway.php
    ├── Entity/WechatUser.php
    └── Resources/config/services_wechat.yaml
```

## `config/`

```
config/
├── bundles.php                     # Enabled bundles (env-conditional)
├── services.yaml                   # Global autowiring/autoconfigure defaults
├── routes.yaml                     # Module route imports under /api/v1
├── routes/                         # framework, security, nelmio_api_doc, web_profiler
├── packages/                       # Per-package config:
│   ├── doctrine.yaml               #   DSN, mappings per module
│   ├── doctrine_migrations.yaml    #   migrations_paths → migrations/
│   ├── messenger.yaml              #   async/failed transports, routing per message
│   ├── security.yaml               #   JWT authenticator, firewalls, roles
│   ├── workflow.yaml               #   Order / Invoice state machines
│   ├── payment.yaml                #   default currency (CNY), system wallet id
│   ├── media.yaml                  #   local storage defaults, upload size/mime limits
│   ├── nelmio_api_doc.yaml         #   Swagger/OpenAPI generation
│   ├── rate_limiter.yaml           #   login/API rate limits
│   └── ... (cache, monolog, serializer, validator, twig, mailer, …)
└── preload.php (reference.php)
```

Module-specific settings ship inside each module under
`src/{Module}/Resources/config/*.yaml` (e.g. `services_identity.yaml`,
`services_payment.yaml`).

## `migrations/`

Versioned Doctrine migrations (`VersionYYYYMMDDHHMMSS.php`) — the only acceptable
way to change the schema. Migration classes are NOT autoloaded into `App\`; they
use the `DoctrineMigrations` namespace. Generate new ones with:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate --no-interaction
```

## `tests/`

PHPUnit suites are grouped by execution tier (`phpunit.dist.xml`):

```
tests/
├── UnitTest/       # Pure unit tests (fast, no container/db) — Core, Common, Identity,
│                   #   Trade, Store, Inventory, Payment, Promotion, Settlement,
│                   #   Storage, Wallet, Wechat
├── Integration/    # Kernel-aware integration & API regression tests (real HTTP-ish),
│                   #   e.g. CoreDynamicQueryApiTest, PaymentTradeIntegrationTest
├── LowValue/       # Lower-value/lighter suites run separately
├── Smoke/          # Smoke tests (e.g. Settlement)
└── Identity/Security/   # Auth-focused tests
    ├── bootstrap.php, IntegrationKernelTestCase.php, IntegrationWebTestCase.php
```

Run them:

```bash
vendor/bin/phpunit                       # UnitTest + Integration (default suite)
vendor/bin/phpunit --testsuite="Low Value"
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
vendor/bin/phpunit --configuration phpunit.dist.xml --testsuite="Project Test Suite"
```

## `docs/`

MkDocs documentation site (`mkdocs.yml` builds `site/`):

```
docs/
├── index.md                  # Project landing / tech stack / quick start
├── design/                   # Design contracts & per-module bundle docs
├── runbooks/                 # Per-module operational runbooks
├── openapi/                  # endpoints.yaml + flow guides
├── testing/                  # Test strategy, invariants, failure modes
├── ai/                       # Context file for AI-assisted development
├── issues/                   # Architecture review & coverage/test audits
└── manual/                   # This manual (Foundation → Operations)
```

## `scripts/`

Developer tooling, not deployed:

```
scripts/
├── build-docs.sh             # Builds bilingual docs site (translate → en/zh)
├── translate-docs.py         # Auto-translation used by build-docs.sh
└── tests/                    # Manual smoke/stress scripts:
    ├── api-smoke.sh, api-stress.sh
    ├── inventory-smoke.sh, store-smoke.sh
    ├── demo-trade-workflow.{sh,php}, simulate-trade.php
    └── test_exception_handler.php
```

## `public/`, `var/`, `docker/`

```
public/
├── index.php                 # Front controller
└── bundles/ ...              # Installed assets (assets:install)
var/                          # Runtime data (git-ignored)
├── cache/, log/, jwt/        # Cache, logs, RSA keys
└── *.db, test_*.db           # Local SQLite datastores used by tests
docker/
├── app/entrypoint.sh         # Starts app; generates JWT keys when absent
└── nginx/default.conf        # Server block proxying / to PHP-FPM
```

## Naming Conventions

| Thing | Convention | Example |
|-------|-----------|---------|
| Module namespace | `App\{Module}\` (StudlyCase module name) | `App\Trade`, `App\Wallet` |
| Controller namespace | `{Module}\Controller` + a scope sub-namespace | `App\Trade\Controller\Manage\OrderController` |
| Controller scopes | `App` (read-mostly user API), `Manage` (admin CRUD), `Webhook` (callbacks), `Public`, `Staff` | `Controller/Manage`, `Controller/Webhook` |
| Entity namespace | `{Module}\Entity` | `App\Payment\Entity\Invoice` |
| Repository namespace | `{Module}\Repository` | `App\Trade\Repository\OrderRepository` |
| Service namespace | `{Module}\Service` | `App\Promotion\Service\PromotionService` |
| Service interface | `{Name}ServiceInterface` beside the implementation | `OrderServiceInterface` |
| Message / handler | `{Module}\Message\*Message` + `{Module}\MessageHandler\*Handler` | `ReservationRequestedMessage` → `ReservationRequestedHandler` |
| CLI commands | Registered under `app:{module}:{verb}` | `app:identity:user:create`, `app:trade:outbox:publish` |
| Module DI file | `Resources/config/services_{module}.yaml` | `services_settlement.yaml` |

## Where to Put New Code

| You are adding… | Put it in |
|-----------------|-----------|
| A REST endpoint on an existing module | `src/{Module}/Controller/App/` (user) or `Controller/Manage/` (admin) using a View mixin + a service method |
| Business logic for an entity | `src/{Module}/Service/{Name}Service.php` implementing `{Name}ServiceInterface` |
| Domain object / persistence | `src/{Module}/Entity/` + `src/{Module}/Repository/`; schema change via a new migration in `migrations/` |
| A new repository query | `src/{Module}/Repository/{Entity}Repository.php` (used from the service, never the controller) |
| Cross-module data flow | An outbox message in the source module (`Entity/*OutboxMessage`) + a `MessageHandler/` in the consuming module; register the message in `config/packages/messenger.yaml` |
| A new payment provider / deposit method | Implement the module's interface and register it — `payment.gateway` tag, `DepositProviderRegistry`, `WithdrawProviderRegistry`, etc. |
| A new pricing step | Implement `App\Trade\Service\Pricing\PriceCalculatorInterface` and tag with `trade.price_calculator` |
| A new module | Copy the vertical-slice shape under `src/{Module}/`, register services in `Resources/config/services_{module}.yaml`, import its routes in `config/routes.yaml` |
| Tests | `tests/UnitTest/{Module}/` (unit), `tests/Integration/` (kernel), matching the tier conventions in `phpunit.dist.xml` |