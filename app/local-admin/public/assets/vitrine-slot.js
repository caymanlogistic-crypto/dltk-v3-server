'use strict';
(() => {
  const maxDigits = 10;
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

  function bind(field) {
    field.inputMode = 'numeric';
    field.placeholder = 'с 00 до 12 - 25.10.26';

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
      if (next.length<=maxDigits) setValue(next,start+value.length);
    });
  }

  window.VitrineSlot = Object.freeze({read,format,bind});
})();
