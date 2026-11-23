'use strict';
(() => {
  const button = document.querySelector('[data-vitrine-export]');
  const table = document.querySelector('.vitrine-table');
  if (!button || !table) return;

  const mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  const spreadsheet = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  const relationships = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  const packageRelationships = 'http://schemas.openxmlformats.org/package/2006/relationships';
  const xmlHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

  function xmlText(value) {
    return value.replace(/_x[0-9a-fA-F]{4}_/g, match => '_x005F_' + match.slice(1))
      .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\uFFFE\uFFFF]/gu, '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\r/g,'&#13;');
  }

  function worksheet(rows) {
    const content = rows.map((row,index) =>
      '<row r="' + (index + 1) + '">' + row.map(value =>
        '<c t="inlineStr"><is><t xml:space="preserve">' + xmlText(value) + '</t></is></c>'
      ).join('') + '</row>'
    );
    return '<worksheet xmlns="' + spreadsheet + '"><sheetData>' + content.join('') + '</sheetData></worksheet>';
  }

  const crcTable = Array.from({length:256}, (_,index) => {
    let crc = index;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (crc & 1 ? 0xEDB88320 : 0);
    return crc;
  });
  function checksum(bytes) {
    let crc = 0xFFFFFFFF;
    for (const byte of bytes) crc = (crc >>> 8) ^ crcTable[(crc ^ byte) & 255];
    return (crc ^ 0xFFFFFFFF) >>> 0;
  }

  function workbook(rows) {
    const files = {
      '[Content_Types].xml': '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
      '_rels/.rels': '<Relationships xmlns="' + packageRelationships + '"><Relationship Id="rId1" Type="' + relationships + '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml': '<workbook xmlns="' + spreadsheet + '" xmlns:r="' + relationships + '"><sheets><sheet name="Таблица" sheetId="1" r:id="rId1"/></sheets></workbook>',
      'xl/_rels/workbook.xml.rels': '<Relationships xmlns="' + packageRelationships + '"><Relationship Id="rId1" Type="' + relationships + '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
      'xl/worksheets/sheet1.xml': worksheet(rows),
    };
    // XLSX is an Open Packaging Convention ZIP. Store the XML parts without compression.
    const encoder = new TextEncoder(), parts = [], directory = [];
    let offset = 0, directorySize = 0;
    for (const [path, xml] of Object.entries(files)) {
      const name = encoder.encode(path), data = encoder.encode(xmlHeader + xml), crc = checksum(data);
      const local = new DataView(new ArrayBuffer(30)), record = new DataView(new ArrayBuffer(46));
      local.setUint32(0,0x04034B50,true);
      local.setUint16(4,20,true);
      local.setUint16(12,33,true); // DOS date: 1980-01-01
      local.setUint32(14,crc,true);
      local.setUint32(18,data.length,true);
      local.setUint32(22,data.length,true);
      local.setUint16(26,name.length,true);
      record.setUint32(0,0x02014B50,true);
      record.setUint16(4,20,true);
      new Uint8Array(record.buffer,6,26).set(new Uint8Array(local.buffer,4,26));
      record.setUint32(42,offset,true);
      parts.push(local.buffer,name,data);
      directory.push(record.buffer,name);
      offset += local.byteLength + name.length + data.length;
      directorySize += record.byteLength + name.length;
    }
    const end = new DataView(new ArrayBuffer(22)), count = Object.keys(files).length;
    end.setUint32(0,0x06054B50,true);
    end.setUint16(8,count,true);
    end.setUint16(10,count,true);
    end.setUint32(12,directorySize,true);
    end.setUint32(16,offset,true);
    return new Blob([...parts,...directory,end.buffer],{type:mime});
  }

  function download(rows, name) {
    const link = document.createElement('a');
    const filename = name.replace(/[\\/:*?"<>|]/g,'-');
    const url = URL.createObjectURL(workbook(rows));
    link.href = url;
    link.download = filename + '.xlsx';
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  }
  button.addEventListener('click', async () => {
    const columns = Array.from(table.querySelectorAll('thead th[data-column]:not([hidden])'));
    const heading = ['Строка', ...columns.map(column => column.querySelector('.vitrine-column-label').textContent)];
    button.disabled = true;
    try {
      const rows = await window.VitrineRows.exportRows(columns.map(column => column.dataset.column));
      download([heading,...rows],button.dataset.vitrineExport);
    } catch (error) {
      window.PlanexNotifications.show({tone:'danger',message:error.message || 'Не удалось скачать Excel.'});
    } finally {
      button.disabled = false;
    }
  });
})();
