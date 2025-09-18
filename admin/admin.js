document.addEventListener("DOMContentLoaded", () => {
  const loginContainer = document.getElementById("login-container");
  const mainContent = document.getElementById("main-content");
  const loginForm = document.getElementById("login-form");
  const usernameInput = document.getElementById("username-input");
  const passwordInput = document.getElementById("password-input");
  const loginError = document.getElementById("login-error");
  const logoutBtn = document.getElementById("logout-btn");

  // 1. Check login status on page load
  checkLoginStatus();

  // 2. Handle login form submission
  loginForm.addEventListener("submit", (e) => {
    e.preventDefault();
    const username = usernameInput.value;
    const password = passwordInput.value;
    loginError.textContent = "";

    fetch("./api.php?action=login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username: username, password: password }),
    })
      .then((response) => {
        if (!response.ok) {
          return response.json().then((err) => Promise.reject(err));
        }
        return response.json();
      })
      .then((data) => {
        if (data.status === "success") {
          showMainContent();
        }
      })
      .catch((error) => {
        loginError.textContent = error.message || "登入失敗";
      });
  });

  // 3. Handle logout
  logoutBtn.addEventListener("click", () => {
    fetch("./api.php?action=logout").then(() => {
      showLogin();
    });
  });

  function checkLoginStatus() {
    fetch("./api.php?action=check_login")
      .then((res) => res.json())
      .then((data) => {
        if (data.loggedIn) {
          showMainContent();
        } else {
          showLogin();
        }
      });
  }

  function showLogin() {
    loginContainer.style.display = "block";
    mainContent.style.display = "none";
    usernameInput.value = "";
    passwordInput.value = "";
  }

  function showMainContent() {
    loginContainer.style.display = "none";
    mainContent.style.display = "block";
    fetchAndRenderBills();
    fetchAndRenderWhitelist();
  }

  function fetchAndRenderBills() {
    const appContainer = document.getElementById("app-container");
    const loadingDiv = document.getElementById("loading");
    loadingDiv.style.display = "block";
    appContainer.innerHTML = ""; // Clear previous content
    appContainer.appendChild(loadingDiv);

    fetch("./api.php?action=get_all_bills")
      .then((response) => {
        if (response.status === 401) {
          showLogin();
          throw new Error("未授權，請重新登入。");
        }
        if (!response.ok) {
          throw new Error(`API 請求失敗: ${response.status}`);
        }
        return response.json();
      })
      .then((result) => {
        loadingDiv.style.display = "none";
        const groups = result.data;

        if (!groups || groups.length === 0) {
          appContainer.innerHTML =
            '<p style="text-align:center;">目前沒有任何帳單資料。</p>';
          return;
        }

        groups.forEach((group) => {
          const groupContainer = document.createElement("div");
          groupContainer.className = "group-container";
          // 預設將有帳單的群組摺疊起來
          if (group.bills.length > 0) {
            groupContainer.classList.add("collapsed");
          }

          const groupHeader = document.createElement("div");
          groupHeader.className = "group-header";
          groupHeader.innerHTML = `群組 ID: <code>${escapeHtml(
            group.groupId
          )}</code>`;
          groupHeader.addEventListener("click", () => {
            groupContainer.classList.toggle("collapsed");
          });
          groupContainer.appendChild(groupHeader);

          const table = document.createElement("table");
          table.className = "bill-table";
          table.innerHTML = `
                      <thead>
                          <tr>
                              <th>帳單名稱</th>
                              <th>總金額</th>
                              <th>付款人</th>
                              <th>參與者</th>
                              <th>狀態</th>
                              <th>建立時間</th>
                          </tr>
                      </thead>
                  `;

          const tbody = document.createElement("tbody");
          group.bills.forEach((bill) => {
            const row = document.createElement("tr");
            const statusClass = bill.is_settled
              ? "status-settled"
              : "status-unsettled";
            const statusText = bill.is_settled ? "已結算" : "未結算";

            row.innerHTML = `
                          <td>${escapeHtml(bill.bill_name)}</td>
                          <td>$${Number(
                            bill.total_amount
                          ).toLocaleString()}</td>
                          <td>${escapeHtml(bill.payer_name)}</td>
                          <td><span class="participants">${escapeHtml(
                            bill.participants_names.join(", ")
                          )}</span></td>
                          <td><span class="${statusClass}">${statusText}</span></td>
                          <td>${new Date(
                            bill.created_at + " UTC"
                          ).toLocaleString()}</td>
                      `;
            tbody.appendChild(row);
          });

          table.appendChild(tbody);
          groupContainer.appendChild(table);
          appContainer.appendChild(groupContainer);
        });
      })
      .catch((error) => {
        if (!error.message.includes("未授權")) {
          loadingDiv.textContent = `載入資料失敗: ${error.message}`;
        }
        console.error("Error fetching bills:", error);
      });
  }

  function fetchAndRenderWhitelist() {
    const whitelistContainer = document.getElementById("whitelist-container");
    const toggle = document.getElementById("whitelist-enabled-toggle");

    fetch("./api.php?action=get_whitelist_settings")
      .then((res) => res.json())
      .then((result) => {
        if (result.status !== "success") throw new Error(result.message);

        const settings = result.data;
        whitelistContainer.style.display = "block";
        toggle.checked = settings.enabled;

        renderGroupList(
          "unregistered-groups",
          "現存活躍群組 (未註冊)",
          settings.unregistered_active
        );
        renderGroupList(
          "pending-groups",
          "待審核群組",
          settings.groups.pending
        );
        renderGroupList(
          "approved-groups",
          "已核准群組",
          settings.groups.approved
        );
        renderGroupList("denied-groups", "已拒絕群組", settings.groups.denied);
      })
      .catch((error) => {
        console.error("Error fetching whitelist settings:", error);
      });

    toggle.removeEventListener("change", handleToggleChange); // Avoid multiple listeners
    toggle.addEventListener("change", handleToggleChange);
  }

  function handleToggleChange(e) {
    const enabled = e.target.checked;
    fetch("./api.php?action=update_whitelist_toggle", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ enabled: enabled }),
    }).then(() => alert("設定已更新！"));
  }

  function renderGroupList(elementId, title, groups) {
    const container = document.getElementById(elementId);
    container.innerHTML = `<h3>${title} (${groups.length})</h3>`;
    if (groups.length === 0) {
      container.innerHTML += "<p>無</p>";
      return;
    }

    const isPending = elementId === "pending-groups";
    const isUnregistered = elementId === "unregistered-groups";
    const showActions = isPending || isUnregistered;

    const table = document.createElement("table");
    table.className = "bill-table";

    const hasCreatedAt = groups.length > 0 && groups[0].created_at;
    const timeHeader = hasCreatedAt ? "<th>申請/建立時間</th>" : "";
    const actionHeader = showActions ? "<th>操作</th>" : "";
    table.innerHTML = `<thead><tr><th>群組 ID</th>${timeHeader}${actionHeader}</tr></thead>`;

    const tbody = document.createElement("tbody");
    groups.forEach((group) => {
      const row = document.createElement("tr");
      const timeCell = hasCreatedAt
        ? `<td>${new Date(group.created_at + " UTC").toLocaleString()}</td>`
        : "";

      let actionButtons = "";
      if (isPending) {
        actionButtons = `
          <button class="btn-approve" data-group-id="${group.group_id}">核准</button>
          <button class="btn-deny" data-group-id="${group.group_id}">拒絕</button>
        `;
      } else if (isUnregistered) {
        actionButtons = `<button class="btn-approve" data-group-id="${group.group_id}">加入白名單</button>`;
      }
      const actionCell = showActions ? `<td>${actionButtons}</td>` : "";

      row.innerHTML = `
        <td><code>${group.group_id}</code></td>
        ${timeCell}
        ${actionCell}
      `;
      tbody.appendChild(row);
    });
    table.appendChild(tbody);
    container.appendChild(table);

    if (showActions) {
      container.querySelectorAll(".btn-approve, .btn-deny").forEach((btn) => {
        btn.addEventListener("click", handleGroupStatusUpdate);
      });
    }
  }

  function handleGroupStatusUpdate(e) {
    e.target.disabled = true;
    e.target.textContent = "處理中...";

    const groupId = e.target.dataset.groupId;
    const status = e.target.classList.contains("btn-approve")
      ? "approved"
      : "denied";
    fetch("./api.php?action=update_group_status", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ groupId, status }),
    }).then(() => {
      alert(`群組 ${status === "approved" ? "已核准" : "已拒絕並移除"}`);
      fetchAndRenderWhitelist();
    });
  }
});

function escapeHtml(unsafe) {
  if (typeof unsafe !== "string") return "";
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
