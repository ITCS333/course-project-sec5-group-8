/*
  Requirement: Add interactivity and data management to the Admin Portal.
*/

let users = [];
let listenersAttached = false;

const userTableBody = document.getElementById("user-table-body");
const addUserForm = document.getElementById("add-user-form");
const changePasswordForm = document.getElementById("password-form");
const searchInput = document.getElementById("search-input");
const tableHeaders = document.querySelectorAll("#user-table thead th");

function createUserRow(user) {
  const row = document.createElement("tr");

  const nameCell = document.createElement("td");
  nameCell.textContent = user.name;

  const emailCell = document.createElement("td");
  emailCell.textContent = user.email;

  const adminCell = document.createElement("td");
  adminCell.textContent = Number(user.is_admin) === 1 ? "Yes" : "No";

  const actionsCell = document.createElement("td");

  const editButton = document.createElement("button");
  editButton.textContent = "Edit";
  editButton.className = "edit-btn";
  editButton.dataset.id = user.id;

  const deleteButton = document.createElement("button");
  deleteButton.textContent = "Delete";
  deleteButton.className = "delete-btn";
  deleteButton.dataset.id = user.id;

  actionsCell.appendChild(editButton);
  actionsCell.appendChild(deleteButton);

  row.appendChild(nameCell);
  row.appendChild(emailCell);
  row.appendChild(adminCell);
  row.appendChild(actionsCell);

  return row;
}

function renderTable(userArray) {
  userTableBody.innerHTML = "";

  userArray.forEach((user) => {
    userTableBody.appendChild(createUserRow(user));
  });
}

async function handleChangePassword(event) {
  event.preventDefault();

  const currentPassword = document.getElementById("current-password").value.trim();
  const newPassword = document.getElementById("new-password").value.trim();
  const confirmPassword = document.getElementById("confirm-password").value.trim();

  if (newPassword !== confirmPassword) {
    alert("Passwords do not match.");
    return;
  }

  if (newPassword.length < 8) {
    alert("Password must be at least 8 characters.");
    return;
  }

  const currentUser =
    typeof localStorage !== "undefined"
      ? JSON.parse(localStorage.getItem("currentUser")) || {}
      : {};

  const id = currentUser.id || 1;

  try {
    const response = await fetch("./api/index.php?action=change_password", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        id: id,
        current_password: currentPassword,
        new_password: newPassword
      })
    });

    const result = await response.json();

    if (!response.ok || result.success === false) {
      alert(result.message || "Failed to update password.");
      return;
    }

    alert("Password updated successfully!");
    changePasswordForm.reset();
  } catch (error) {
    console.error(error);
    alert("Failed to update password.");
  }
}

async function handleAddUser(event) {
  event.preventDefault();

  const name = document.getElementById("user-name").value.trim();
  const email = document.getElementById("user-email").value.trim();
  const password = document.getElementById("default-password").value.trim();
  const is_admin = Number(document.getElementById("is-admin").value);

  if (!name || !email || !password) {
    alert("Please fill out all required fields.");
    return;
  }

  if (password.length < 8) {
    alert("Password must be at least 8 characters.");
    return;
  }

  try {
    const response = await fetch("./api/index.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        name: name,
        email: email,
        password: password,
        is_admin: is_admin
      })
    });

    const result = await response.json();

    if (!response.ok || result.success === false) {
      alert(result.message || "Failed to add user.");
      return;
    }

    addUserForm.reset();
    await loadUsersAndInitialize();
  } catch (error) {
    console.error(error);
    alert("Failed to add user.");
  }
}

async function handleTableClick(event) {
  const target = event.target;

  if (target.classList.contains("delete-btn")) {
    const id = target.dataset.id;

    if (!confirm("Are you sure you want to delete this user?")) {
      return;
    }

    try {
      const response = await fetch("./api/index.php?id=" + encodeURIComponent(id), {
        method: "DELETE"
      });

      const result = await response.json();

      if (!response.ok || result.success === false) {
        alert(result.message || "Failed to delete user.");
        return;
      }

      users = users.filter((user) => String(user.id) !== String(id));
      renderTable(users);
    } catch (error) {
      console.error(error);
      alert("Failed to delete user.");
    }
  }

  if (target.classList.contains("edit-btn")) {
    const id = target.dataset.id;
    const user = users.find((item) => String(item.id) === String(id));

    if (!user) {
      alert("User not found.");
      return;
    }

    const newName = prompt("Enter new name:", user.name);
    if (newName === null) return;

    const newEmail = prompt("Enter new email:", user.email);
    if (newEmail === null) return;

    const newAdmin = prompt("Is admin? Enter 1 for yes or 0 for no:", user.is_admin);
    if (newAdmin === null) return;

    try {
      const response = await fetch("./api/index.php", {
        method: "PUT",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          id: id,
          name: newName.trim(),
          email: newEmail.trim(),
          is_admin: Number(newAdmin)
        })
      });

      const result = await response.json();

      if (!response.ok || result.success === false) {
        alert(result.message || "Failed to update user.");
        return;
      }

      await loadUsersAndInitialize();
    } catch (error) {
      console.error(error);
      alert("Failed to update user.");
    }
  }
}

function handleSearch() {
  const searchTerm = searchInput.value.toLowerCase();

  if (!searchTerm) {
    renderTable(users);
    return;
  }

  const filteredUsers = users.filter((user) => {
    return (
      user.name.toLowerCase().includes(searchTerm) ||
      user.email.toLowerCase().includes(searchTerm)
    );
  });

  renderTable(filteredUsers);
}

function handleSort(event) {
  const columnIndex = event.currentTarget.cellIndex;
  const properties = ["name", "email", "is_admin", null];
  const property = properties[columnIndex];

  if (!property) {
    return;
  }

  const currentDirection = event.currentTarget.dataset.sortDir || "desc";
  const newDirection = currentDirection === "asc" ? "desc" : "asc";

  event.currentTarget.dataset.sortDir = newDirection;

  users.sort((a, b) => {
    let result;

    if (property === "is_admin") {
      result = Number(a[property]) - Number(b[property]);
    } else {
      result = String(a[property]).localeCompare(String(b[property]));
    }

    return newDirection === "asc" ? result : -result;
  });

  renderTable(users);
}

async function loadUsersAndInitialize() {
  try {
    const response = await fetch("./api/index.php");

    if (!response.ok) {
      alert("Failed to load users.");
      return;
    }

    const result = await response.json();
    users = result.data || [];

    renderTable(users);

    if (!listenersAttached) {
      changePasswordForm.addEventListener("submit", handleChangePassword);
      addUserForm.addEventListener("submit", handleAddUser);
      userTableBody.addEventListener("click", handleTableClick);
      searchInput.addEventListener("input", handleSearch);

      tableHeaders.forEach((th) => {
        th.addEventListener("click", handleSort);
      });

      listenersAttached = true;
    }
  } catch (error) {
    console.error(error);
    alert("Failed to load users.");
  }
}

loadUsersAndInitialize();

