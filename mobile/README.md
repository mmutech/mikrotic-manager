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
- Bluetooth printer selection, connection status, and 58mm/80mm paper width

## Voucher printing

Native builds try the selected or connected Bluetooth ESC/POS printer first and fall back to sharing a PDF when no printer is available. Open **Printer settings** from the Vouchers screen, voucher detail screen, or Account screen to scan for printers and choose the paper width (default: 58mm).

Bluetooth printing uses `@ricka7x/expo-thermal-printer` and requires an Android or iOS development build on a real device. Expo Go and web use PDF/browser printing. On Android, pair the printer in system Bluetooth settings before scanning. On iOS, leave the printer powered on and nearby while the app scans for BLE devices.

Connected time and consumption are displayed from live RouterOS reads. The app never submits `login_address` or `connected_time_allowance`. A plan value of `0` is shown as “No maximum time limit”.
