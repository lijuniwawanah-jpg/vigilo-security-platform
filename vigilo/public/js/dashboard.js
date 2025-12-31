// Get user info from localStorage
const user = JSON.parse(localStorage.getItem('user'));
if(user){
    document.getElementById('username').textContent = `Welcome, ${user.fullName}`;
} else {
    // If no user, redirect to login
    window.location.href = "login.html";
}

// Logout button
document.getElementById('logout-btn').addEventListener('click', () => {
    localStorage.removeItem('user');
    window.location.href = "login.html";
});

// TODO: Fetch stats & recent docs dynamically from backend
// Example static values
document.getElementById('total-docs').textContent = 12;
document.getElementById('shared-links').textContent = 5;
document.getElementById('verified-docs').textContent = 8;
document.getElementById('pending-verification').textContent = 4;

// Example recent documents
const recentDocs = [
    {name: "Report Q1.pdf", uploader: "Alice", status: "Verified", date: "2025-11-28"},
    {name: "Project Plan.docx", uploader: "Bob", status: "Pending", date: "2025-11-27"},
    {name: "Invoice Nov.xlsx", uploader: "Charlie", status: "Verified", date: "2025-11-26"},
];

const tbody = document.getElementById('recent-docs-body');
tbody.innerHTML = ""; // Clear default row
recentDocs.forEach(doc => {
    const row = `<tr>
        <td>${doc.name}</td>
        <td>${doc.uploader}</td>
        <td>${doc.status}</td>
        <td>${doc.date}</td>
    </tr>`;
    tbody.innerHTML += row;
});
