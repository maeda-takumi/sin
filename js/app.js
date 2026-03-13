const modal = document.getElementById('memberModal');
const form = document.getElementById('memberForm');
const flash = document.getElementById('flashMessage');
const modalTitle = document.getElementById('modalTitle');
const formAction = document.getElementById('formAction');
const formMode = document.getElementById('formMode');
const memberId = document.getElementById('memberId');
const email = document.getElementById('email');
const userName = document.getElementById('user_name');
const password = document.getElementById('password');
const firstName = document.getElementById('first_name');
const lastName = document.getElementById('last_name');
const membershipLevel = document.getElementById('membership_level');

function showFlash(message, type) {
    flash.textContent = message;
    flash.classList.remove('hidden', 'success', 'error');
    flash.classList.add(type);
}

function closeModal() {
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
}

function openModal() {
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
}

function setReadonlyForEdit(enabled) {
    [email, userName, password, firstName, lastName].forEach((el) => {
        el.readOnly = enabled;
        el.required = !enabled || el === firstName || el === lastName ? el.required : false;
    });
    document.getElementById('generatePasswordBtn').disabled = enabled;
}

async function isPasswordUsed(candidate) {
    const body = new FormData();
    body.append('action', 'check_password');
    body.append('password', candidate);
    const response = await fetch('index.php', { method: 'POST', body });
    const data = await response.json();
    return !!data.exists;
}

function randomPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*';
    let out = '';
    for (let i = 0; i < 12; i += 1) {
        out += chars[Math.floor(Math.random() * chars.length)];
    }
    return out;
}

async function generateUniquePassword() {
    let candidate = randomPassword();
    while (await isPasswordUsed(candidate)) {
        candidate = randomPassword();
    }
    password.value = candidate;
}

document.getElementById('openCreateModalBtn')?.addEventListener('click', async () => {
    form.reset();
    modalTitle.textContent = '会員追加';
    formAction.value = 'create_member';
    formMode.value = 'create';
    memberId.value = '';
    setReadonlyForEdit(false);
    membershipLevel.value = '2';
    await generateUniquePassword();
    openModal();
});

document.querySelectorAll('.editBtn').forEach((btn) => {
    btn.addEventListener('click', (event) => {
        const trigger = event.currentTarget;
        modalTitle.textContent = '会員編集';
        formAction.value = 'update_level';
        formMode.value = 'edit';
        memberId.value = trigger.dataset.id;
        email.value = trigger.dataset.email;
        userName.value = trigger.dataset.userName;
        password.value = '********';
        firstName.value = trigger.dataset.firstName;
        lastName.value = trigger.dataset.lastName;
        membershipLevel.value = trigger.dataset.membershipLevel === '4' ? '4' : '2';
        setReadonlyForEdit(true);
        openModal();
    });
});

document.querySelectorAll('[data-close-modal="1"]').forEach((el) => {
    el.addEventListener('click', closeModal);
});

document.getElementById('generatePasswordBtn')?.addEventListener('click', generateUniquePassword);

form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const body = new FormData(form);
    const response = await fetch('index.php', { method: 'POST', body });
    const data = await response.json();
    showFlash(data.message + (data.api_message ? ` (${data.api_message})` : ''), data.success ? 'success' : 'error');
    if (data.success) {
        closeModal();
        window.setTimeout(() => window.location.reload(), 500);
    }
});
