/* script.js — install tab switcher and copy-command for index.html */

function switchTab(id) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-' + id).classList.add('active');
}

function copyCmd(el) {
    const text = el.textContent.replace('$ ', '').replace(/ #.*$/, '').trim();
    navigator.clipboard.writeText(text).then(() => {
        const orig = el.style.borderColor;
        el.style.borderColor = 'var(--green)';
        setTimeout(() => el.style.borderColor = orig, 800);
    });
}
