* { box-sizing: border-box; }
.mcu-label { display: flex; flex-direction: column; gap: 0.6mm; width: var(--label-width, 80mm); height: var(--label-height, 40mm); padding: 2mm; background: white; color: black; font: 7pt/1.15 Arial, sans-serif; overflow-wrap: anywhere; }
.mcu-label[hidden] { display: none !important; }
.mcu-label strong, .mcu-label span { display: block; flex: 0 0 auto; }
.mcu-label img { display: block; width: 100%; height: 12mm; min-height: 10mm; margin: auto 0 0; object-fit: fill; }
.mcu-label[data-barcode-type="qr"] img { width: 100%; height: 0; min-height: 10mm; flex: 1 1 0; object-fit: contain; }
.mcu-label[data-barcode-type="qr"][data-label-layout="horizontal"] { display: grid; grid-template-columns: minmax(0, 1fr) min(calc(var(--label-height, 40mm) - 4mm), 45%); grid-template-rows: min-content min-content minmax(0, 1fr); column-gap: 2mm; align-items: start; }
.mcu-label[data-barcode-type="qr"][data-label-layout="horizontal"] strong, .mcu-label[data-barcode-type="qr"][data-label-layout="horizontal"] span { grid-column: 1; min-width: 0; }
.mcu-label[data-barcode-type="qr"][data-label-layout="horizontal"] img { grid-column: 2; grid-row: 1 / 4; width: 100%; height: 100%; min-height: 0; margin: 0; align-self: stretch; }
@media print { body { margin: 0; background: white; } .mcu-label { break-inside: avoid; break-after: page; } .mcu-label:last-child { break-after: auto; } }
