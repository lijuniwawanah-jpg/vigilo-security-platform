// public/js/auth.js
async function requestOTP(){
  const phone = document.getElementById('phone').value;
  if(!phone){ document.getElementById('msg').innerText='Enter phone'; return; }
  const form = new URLSearchParams(); form.append('phone', phone);
  const r = await fetch('/api/auth/request_otp.php', { method:'POST', body: form });
  const j = await r.json();
  if(j.success) {
    document.getElementById('msg').innerText = 'OTP sent (demo): ' + (j.otp || '');
    document.getElementById('otpArea').style.display = 'block';
  } else {
    document.getElementById('msg').innerText = j.message || 'Error';
  }
}

async function verifyOTP(){
  const phone = document.getElementById('phone').value;
  const otp = document.getElementById('otp').value;
  const form = new URLSearchParams(); form.append('phone', phone); form.append('otp', otp);
  const r = await fetch('/api/auth/verify_otp.php', { method:'POST', body: form });
  const j = await r.json();
  if(j.success) {
    localStorage.setItem('vig_token', j.token);
    window.location = '/public/dashboard.html';
  } else {
    alert(j.message || 'Login failed');
  }
}
