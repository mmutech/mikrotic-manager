# MMU Tech Mobile

Expo + TypeScript client for the Laravel `/api/v1` mobile API. It uses Expo Router, secure token storage, and a small custom UI layer.

## Setup

```bash
cd mobile
npm install
cp .env.example .env
npm run start
```

Set `EXPO_PUBLIC_API_BASE_URL` to either the full API v1 URL or the Laravel root URL. If it does not end with `/api/v1`, the client appends it.

Recommended values:

- Android emulator: `http://10.0.2.2:8000/api/v1`
- iOS simulator / web: `http://localhost:8000/api/v1`
- Physical device: your computer's LAN address, for example `http://192.168.1.10:8000/api/v1`

## Scripts

```bash
npm run typecheck
npm run web
npm run android
npm run ios
npx expo export --platform web
```

## Screens

- Login with Sanctum personal access token storage
- Dashboard and subscription status
- Router list, detail, health checks, pending jobs, read-only HotSpot profile discovery, and tracked connection tests
- Plan list, create, edit, activate/deactivate, and deletion guard
- Voucher list, stats, detail, live RouterOS usage, generate, provision/revoke, and bulk actions
- Router status and permission-scoped reports
- Account/subscription management for admins

Connected time and consumption are displayed from live RouterOS reads. The app never submits `login_address` or `connected_time_allowance`. A plan value of `0` is shown as “No maximum time limit”.
