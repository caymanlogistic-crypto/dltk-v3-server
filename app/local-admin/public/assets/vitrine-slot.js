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

  function bindDigits(field, {maxDigits, placeholder, format, read}) {
    field.inputMode = 'numeric';
    field.placeholder = placeholder;

    function setValue(digits,caret) {
      field.value = format(digits);
      const positions = Array.from(field.value.matchAll(/[0-9]/g));
      const next = caret ? (positions[caret-1]?.index ?? field.value.length-1)+1 : (positions[0]?.index ?? 0);
      field.setSelectionRange(next,next);
    }
    const digitOffset = position => digitsOnly(field.value.slice(0,position)).length;

    field.addEventListener('beforeinput',event=>{
      const start = digitOffset(field.selectionStart);
      const end = digitOffset(field.selectionEnd);
      const digits = digitsOnly(field.value);
      if (event.inputType === 'insertText' && (event.data === null || !/^[0-9]+$/.test(event.data)
          || digits.length-end+start+event.data.length>maxDigits)) {
        event.preventDefault();
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
      setValue(digitsOnly(field.value).slice(0,maxDigits),position);
    });
    field.addEventListener('paste',event=>{
      event.preventDefault();
      const value = read(event.clipboardData.getData('text/plain'));
      if (!value) return;
      const start = digitOffset(field.selectionStart);
      const end = digitOffset(field.selectionEnd);
      const digits = digitsOnly(field.value), next = digits.slice(0,start)+value+digits.slice(end);
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

  function readTime(value) {
    const input = value.trim();
    if (/^[0-9]{1,4}$/.test(input)) return input;
    const parts = input.match(/^([0-9]{1,2}):([0-9]{2})$/);
    return parts ? parts[1].padStart(2,'0')+parts[2] : '';
  }

  function mask(maxDigits,placeholder,format,read) {
    return {format,read,bind:field=>bindDigits(field,{maxDigits,placeholder,format,read})};
  }
  window.VitrineSlot = Object.freeze({
    ...mask(maxDigits,'с 00 до 12 - 25.10.26',format,read),requestLabel,isRequest
  });
  window.VitrineCompactInput = Object.freeze({
    date:mask(6,'25.10.26',digits=>digits.match(/.{1,2}/g)?.join('.') ?? '',readDate),
    time:mask(4,'08:00',digits=>digits.length>2
      ? digits.slice(0,-2)+':'+digits.slice(-2) : digits,readTime)
  });
})();
