'use strict';
(() => {
  const maxDigits = 10;
  const requestLabel = 'Слот?';
  const isRequest = value => /^слот\s*\?$/iu.test(value.trim());
  const digitsOnly = value => value.replace(/[^0-9]/g,'');
  function read(value) {
    const input = value.trim();
    if (/^[0-9]{1,10}$/.test(input)) return input;
    if (!/^с [0-9]{2} до [0-9]{2} - [0-9]{2}\.[0-9]{2}\.(?:20)?[0-9]{2}$/.test(input)) return '';
    const digits = digitsOnly(input);
    return digits.slice(0,8)+digits.slice(-2);
  }

  function format(digits) {
    if (!digits) return '';
    let value = 'с '+digits.slice(0,2);
    if (digits.length>2) value += ' до '+digits.slice(2,4);
    if (digits.length>4) value += ' - '+digits.slice(4,6);
    if (digits.length>6) value += '.'+digits.slice(6,8);
    if (digits.length>8) value += '.'+digits.slice(8,maxDigits);
    return value;
  }

  function bindDigits(field, {maxDigits, placeholder, format, read, digitsOnlyPaste, phone}) {
    field.inputMode = 'numeric';
    field.placeholder = placeholder;

    // Keep the typed digits while showing a country prefix inferred from the number.
    let phoneDraft;
    function setValue(digits,caret) {
      field.value = format(digits);
      if (phone) phoneDraft={value:field.value,digits};
      const positions = Array.from(field.value.matchAll(/[0-9]/g));
      const visibleCaret=phone && caret ? Math.max(0,caret+positions.length-digits.length) : caret;
      const next = visibleCaret ? (positions[visibleCaret-1]?.index ?? field.value.length-1)+1 : (positions[0]?.index ?? 0);
      field.setSelectionRange(next,next);
    }
    const fieldDigits=()=>{
      if (!phone) return digitsOnly(field.value);
      return field.value===phoneDraft?.value ? phoneDraft.digits : read(field.value)||digitsOnly(field.value);
    };
    const digitOffset=position=>{
      const visible=digitsOnly(field.value.slice(0,position)).length;
      return phone && position>0 ? Math.max(0,visible+fieldDigits().length-digitsOnly(field.value).length) : visible;
    };
    if (phone) field.form?.addEventListener('reset',()=>{phoneDraft=undefined;});

    field.addEventListener('beforeinput',event=>{
      const start = digitOffset(field.selectionStart);
      const end = digitOffset(field.selectionEnd);
      const digits=fieldDigits();
      if (event.inputType === 'insertText' && (event.data === null || !/^[0-9]+$/.test(event.data)
          || digits.length-end+start+event.data.length>maxDigits)) {
        event.preventDefault();
        return;
      }
      if (phone && event.inputType === 'insertText') {
        event.preventDefault();
        setValue(digits.slice(0,start)+event.data+digits.slice(end),start+event.data.length);
        return;
      }
      if (event.inputType !== 'deleteContentBackward' && event.inputType !== 'deleteContentForward') return;
      event.preventDefault();
      const hasRange = field.selectionStart !== field.selectionEnd && start !== end;
      const from = hasRange || event.inputType === 'deleteContentForward' ? start : Math.max(0,start-1);
      const to = hasRange || event.inputType === 'deleteContentBackward' ? end : end+1;
      setValue(digits.slice(0,from)+digits.slice(to),from);
    });
    field.addEventListener('input',()=>{
      const position = digitOffset(field.selectionStart);
      setValue(fieldDigits().slice(0,maxDigits),position);
    });
    if (digitsOnlyPaste || phone) field.addEventListener('drop',event=>event.preventDefault());
    field.addEventListener('paste',event=>{
      event.preventDefault();
      const text=event.clipboardData.getData('text/plain');
      const value=digitsOnlyPaste ? (/^[0-9]+$/.test(text) ? text : '') : read(text);
      if (!value) return;
      const start = digitOffset(field.selectionStart);
      const end = digitOffset(field.selectionEnd);
      const digits=fieldDigits(), next = digits.slice(0,start)+value+digits.slice(end);
      if (next.length>maxDigits) return;
      setValue(next,start+value.length);
      field.dispatchEvent(new InputEvent('input',{inputType:'insertFromPaste',bubbles:true}));
    });
  }

  function readDate(value) {
    const input = value.trim();
    if (/^[0-9]{1,6}$/.test(input)) return input;
    const local = input.match(/^([0-9]{2})\.([0-9]{2})\.(?:20)?([0-9]{2})$/);
    if (local) return local.slice(1).join('');
    const iso = input.match(/^20([0-9]{2})-([0-9]{2})-([0-9]{2})$/);
    return iso ? iso.slice(1).reverse().join('') : '';
  }

  function readDocumentDate(value) {
    const input=value.trim();
    if (/^[0-9]{1,8}$/.test(input)) return input;
    const local=/^([0-9]{2})\.([0-9]{2})\.([0-9]{4})$/.exec(input);
    if (local) return local.slice(1).join('');
    const iso=/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(input);
    return iso ? iso.slice(1).reverse().join('') : '';
  }

  function readPhone(value) {
    const input=value.trim();
    if (!/^\+?[0-9(). \u00a0\u2009\u202f\-–—]+$/.test(input)) return '';
    const digits=digitsOnly(input);
    if (!digits) return '';
    const knownCode=/^(?:7[0-9]{10}|375[0-9]{9})$/.test(digits);
    return input.startsWith('+') && !knownCode ? '00'+digits : digits;
  }

  function formatPhone(digits) {
    const explicit=digits.startsWith('00');
    const number=explicit ? digits.slice(2) : digits;
    const belarus=number.match(/^375([1-4][0-9])([0-9]{3})([0-9]{2})([0-9]{2})$/)
      || (!explicit && number.match(/^810375([1-4][0-9])([0-9]{3})([0-9]{2})([0-9]{2})$/))
      || (!explicit && number.match(/^80(25|29|33|44)([0-9]{3})([0-9]{2})([0-9]{2})$/));
    if (belarus) return '+375 ('+belarus[1]+') '+belarus.slice(2).join(' ');
    const local=number.match(/^7([0-9]{3})([0-9]{3})([0-9]{2})([0-9]{2})$/)
      || (!explicit && number.match(/^8?([3489][0-9]{2})([0-9]{3})([0-9]{2})([0-9]{2})$/));
    if (local) return '+7 ('+local[1]+') '+local.slice(2).join(' ');
    return explicit && /^[1-9][0-9]{6,14}$/.test(number) ? '+'+number : digits;
  }

  function bindPhone(field) {
    bindDigits(field,{maxDigits:17,placeholder:'79160000000',format:formatPhone,read:readPhone,phone:true});
  }

  function validDate(digits) {
    if (digits.length!==6) return false;
    const [day,month,year]=digits.match(/../g).map(Number);
    const checked=new Date(Date.UTC(2000+year,month-1,day));
    return checked.getUTCMonth()===month-1 && checked.getUTCDate()===day;
  }

  function readPercent(value) {
    const number=value.trim().replace(/\s*%$/,'').replace('.',',');
    return /^[0-9]{1,3}(?:,[0-9]{1,2})?$/.test(number) ? number : '';
  }

  function bindPercent(field) {
    field.inputMode='decimal';
    field.placeholder='10,5';
    const replaceSelection=text=>field.value.slice(0,field.selectionStart)+text+field.value.slice(field.selectionEnd);
    const partial=/^[0-9]{0,3}(?:[.,][0-9]{0,2})?$/;
    field.addEventListener('beforeinput',event=>{
      if (event.inputType==='insertText' && !partial.test(replaceSelection(event.data??''))) event.preventDefault();
    });
    field.addEventListener('input',()=>{
      const {selectionStart,selectionEnd}=field;
      field.value=field.value.replace('.',',');
      field.setSelectionRange(selectionStart,selectionEnd);
    });
    field.addEventListener('paste',event=>{
      event.preventDefault();
      const value=readPercent(event.clipboardData.getData('text/plain'));
      if (!value || !partial.test(replaceSelection(value))) return;
      field.setRangeText(value,field.selectionStart,field.selectionEnd,'end');
      field.dispatchEvent(new InputEvent('input',{inputType:'insertFromPaste',bubbles:true}));
    });
  }

  function readTime(value) {
    const input = value.trim();
    if (/^[0-9]{1,4}$/.test(input)) return input;
    const parts = input.match(/^([0-9]{1,2}):([0-9]{2})$/);
    return parts ? parts[1].padStart(2,'0')+parts[2] : '';
  }

  function readInteger(value) {
    const input = value.trim();
    return /^(?:[0-9]+|[0-9]{1,3}(?:[ \u00a0\u202f][0-9]{3})+)$/.test(input) ? digitsOnly(input) : '';
  }

  function formatInteger(digits) {
    const first = digits.length % 3;
    const groups = digits.slice(first).match(/[0-9]{3}/g) ?? [];
    if (first) groups.unshift(digits.slice(0,first));
    return groups.join(' ');
  }

  function mask(maxDigits,placeholder,format,read) {
    return {format,read,bind:(field,{digitsOnlyPaste=false}={})=>bindDigits(field,{maxDigits,placeholder,format,read,digitsOnlyPaste})};
  }
  window.VitrineSlot = Object.freeze({
    ...mask(maxDigits,'с 00 до 12 - 25.10.26',format,read),requestLabel,isRequest
  });
  window.VitrineCompactInput = Object.freeze({
    date:{...mask(6,'25.10.26',digits=>digits.match(/.{1,2}/g)?.join('.') ?? '',readDate),
      valid:validDate,invalidMessage:'Введите действительную дату в формате ДДММГГ.'},
    documentDate:mask(8,'ДД.ММ.ГГГГ',digits=>[digits.slice(0,2),digits.slice(2,4),digits.slice(4)].filter(Boolean).join('.'),readDocumentDate),
    phone:{format:formatPhone,read:readPhone,bind:bindPhone},
    time:mask(4,'08:00',digits=>digits.length>2
      ? digits.slice(0,-2)+':'+digits.slice(-2) : digits,readTime),
    weight:mask(15000,'25 990',formatInteger,readInteger),
    distance:mask(15000,'1 250',formatInteger,readInteger),
    'cargo-value':mask(15000,'1 000 000',formatInteger,readInteger),
    'cargo-places':mask(15000,'1 250',formatInteger,readInteger),
    money:{...mask(15000,'25 000',formatInteger,readInteger),
      valid:value=>Number(value)>=1000 && Number(value)<=9999999,
      invalidMessage:'Введите сумму от 1 000 до 9 999 999.'},
    percent:{read:readPercent,format:value=>value,bind:bindPercent,
      valid:value=>Number(value.replace(',','.'))>=0 && Number(value.replace(',','.'))<=100,
      invalidMessage:'Введите процент от 0 до 100, до двух знаков после запятой.'}
  });
})();
