import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';

import {
  Badge,
  Button,
  Card,
  InfoRow,
  ListRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  SelectChips,
} from '@/src/components/ui';
import { useRefresh } from '@/src/hooks/useRefresh';
import {
  connectPrinter,
  disconnectPrinter,
  findAvailablePrinters,
  printerErrorText,
  readPrinterStatus,
  subscribeToConnectionChanged,
  type ConnectionSubscription,
  type PrinterStatus,
} from '@/src/print/printer';
import {
  clearPrinterSelection,
  DEFAULT_PRINTER_SETTINGS,
  readPrinterSettings,
  savePaperWidth,
  savePrinterSelection,
  type PaperWidth,
  type PrinterSettings,
} from '@/src/print/printerSettings';
import type { PrinterDevice } from '@ricka7x/expo-thermal-printer';

const PAPER_OPTIONS: Array<{ label: string; value: PaperWidth }> = [
  { label: '58mm', value: '58mm' },
  { label: '80mm', value: '80mm' },
];

export default function PrinterSettingsScreen() {
  const { register } = useRefresh();
  const [settings, setSettings] = useState<PrinterSettings>(DEFAULT_PRINTER_SETTINGS);
  const [status, setStatus] = useState<PrinterStatus>({
    supported: false,
    bluetoothEnabled: false,
    connected: false,
    address: null,
  });
  const [devices, setDevices] = useState<PrinterDevice[]>([]);
  const [loadingInitial, setLoadingInitial] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const reload = useCallback(async () => {
    const [nextSettings, nextStatus] = await Promise.all([
      readPrinterSettings(),
      readPrinterStatus(),
    ]);

    setSettings(nextSettings);
    setStatus(nextStatus);
    setLoadingInitial(false);
  }, []);

  useEffect(() => {
    void reload();
  }, [reload]);

  useEffect(() => register(reload), [register, reload]);

  useEffect(() => {
    let active = true;
    let subscription: ConnectionSubscription | null = null;

    void subscribeToConnectionChanged(() => {
      void reload();
    }).then((next) => {
      if (active) {
        subscription = next;
      } else {
        next.remove();
      }
    });

    return () => {
      active = false;
      subscription?.remove();
    };
  }, [reload]);

  async function run(key: string, action: () => Promise<void>): Promise<void> {
    if (busy) return;

    setBusy(key);
    setNotice(null);

    try {
      await action();
    } catch (error) {
      setNotice(printerErrorText(error));
    } finally {
      setBusy(null);
    }
  }

  function handleScan(): void {
    void run('scan', async () => {
      const found = await findAvailablePrinters();
      setDevices(found);
      setNotice(
        found.length > 0
          ? `${found.length} printer(s) found.`
          : 'No printers found. On Android, pair the printer in system Bluetooth settings first.',
      );
    });
  }

  function handleConnect(device: PrinterDevice): void {
    void run(`connect-${device.address}`, async () => {
      await connectPrinter(device.address);
      await savePrinterSelection(device.address, device.name);
      await reload();
      setNotice(`${device.name || device.address} selected and connected.`);
    });
  }

  function handleConnectSelected(): void {
    const address = settings.address;

    if (!address) {
      setNotice('Select a printer from the scan list first.');
      return;
    }

    void run('connect-selected', async () => {
      await connectPrinter(address);
      await reload();
      setNotice('Printer connected.');
    });
  }

  function handleDisconnect(): void {
    void run('disconnect', async () => {
      await disconnectPrinter();
      await reload();
      setNotice('Printer disconnected. The selection is kept for next time.');
    });
  }

  function handleForget(): void {
    void run('forget', async () => {
      await disconnectPrinter().catch(() => {});
      await clearPrinterSelection();
      await reload();
      setNotice('Printer selection cleared.');
    });
  }

  function handlePaperWidth(width: PaperWidth): void {
    if (width === settings.paperWidth) return;

    void run(`width-${width}`, async () => {
      await savePaperWidth(width);
      await reload();
      setNotice(`Paper width set to ${width}.`);
    });
  }

  return (
    <Screen
      header={
        <PageHeader
          back
          title="Bluetooth printer"
          subtitle="Select a printer and paper width"
        />
      }>
      {notice ? (
        <Card>
          <InfoRow label="Result" value={notice} />
        </Card>
      ) : null}

      <Section title="Printer status">
        <Card>
          {loadingInitial ? (
            <LoadingState label="Loading printer settings…" />
          ) : (
            <>
              <InfoRow label="Availability" value={status.supported ? 'Bluetooth printing' : 'Native build required'} />
              <InfoRow label="Bluetooth" value={status.bluetoothEnabled ? 'On' : 'Off or unavailable'} />
              <InfoRow
                label="Connection"
                value={
                  <Badge
                    label={status.connected ? 'Connected' : 'Not connected'}
                    tone={status.connected ? 'success' : 'neutral'}
                  />
                }
              />
              <InfoRow label="Selected printer" value={settings.name ?? 'None'} />
              <InfoRow label="Address" value={settings.address ?? '—'} />
              <InfoRow label="Paper width" value={settings.paperWidth} />
              {!status.supported ? (
                <InfoRow
                  label="Note"
                  value="Bluetooth printing works in an Android or iOS development build. Web and Expo Go use PDF printing."
                />
              ) : null}
            </>
          )}
        </Card>
      </Section>

      <Section title="Paper width">
        <Card>
          <SelectChips
            options={PAPER_OPTIONS}
            value={settings.paperWidth}
            onChange={(value) => {
              if (value) handlePaperWidth(value);
            }}
          />
          <InfoRow label="Layout" value="58mm uses 32 columns; 80mm uses 48 columns." />
        </Card>
      </Section>

      <Section title="Find printers">
        <Card>
          <Button
            label="Scan for printers"
            variant="secondary"
            compact
            loading={busy === 'scan'}
            disabled={!status.supported || busy !== null}
            onPress={handleScan}
          />
          <View style={{ height: 10 }} />
          <InfoRow
            label="Android"
            value="Pair the printer in system Bluetooth settings, then scan here."
          />
          <InfoRow
            label="iOS"
            value="Keep the printer on and nearby. Scanning takes about five seconds."
          />

          {devices.length > 0 ? (
            devices.map((device) => {
              const isConnected = status.address === device.address && status.connected;

              return (
                <ListRow
                  key={device.address}
                  title={device.name || device.address}
                  subtitle={`${device.address}${device.bonded ? ' · paired' : ''}`}
                  trailing={
                    isConnected ? (
                      <Badge label="Connected" tone="success" />
                    ) : (
                      <Button
                        label="Select"
                        compact
                        disabled={busy !== null}
                        loading={busy === `connect-${device.address}`}
                        onPress={() => handleConnect(device)}
                      />
                    )
                  }
                />
              );
            })
          ) : (
            <ListRow title="No printers found yet." subtitle="Run a scan to see available devices." />
          )}
        </Card>
      </Section>

      <Section title="Connection">
        <Card>
          <Button
            label="Connect selected printer"
            compact
            loading={busy === 'connect-selected'}
            disabled={!status.supported || !settings.address || status.connected || busy !== null}
            onPress={handleConnectSelected}
          />
          <View style={{ height: 10 }} />
          <Button
            label="Disconnect"
            variant="secondary"
            compact
            loading={busy === 'disconnect'}
            disabled={!status.connected || busy !== null}
            onPress={handleDisconnect}
          />
          <View style={{ height: 10 }} />
          <Button
            label="Forget printer"
            variant="danger"
            compact
            loading={busy === 'forget'}
            disabled={(!settings.address && !status.connected) || busy !== null}
            onPress={handleForget}
          />
        </Card>
      </Section>
    </Screen>
  );
}
