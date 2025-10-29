// Example JavaScript for handling dropdowns
document.addEventListener("DOMContentLoaded", function() {
    // Dropdown toggle functionality for User profile
    let dropdownButton = document.getElementById('dropdownMenuButton');
    let dropdownMenu = document.querySelector('.dropdown-menu');

    dropdownButton.addEventListener('click', function() {
        dropdownMenu.classList.toggle('show');
    });
});
