document.addEventListener("DOMContentLoaded", () => {
  const liffId = "2008005425-9w3Ydy41";
  const settleModal = document.getElementById("settle-modal");
  const closeModalBtn = document.querySelector(".close-button");
  const confirmSettleBtn = document.getElementById("btn-confirm-settle");
  const cancelSettleBtn = document.getElementById("btn-cancel-settle");

  liff
    .init({ liffId })
    .then(() => {
      if (!liff.isLoggedIn()) {
        liff.login();
        return;
      }

      const urlParams = new URLSearchParams(window.location.search);
      const groupId = urlParams.get("groupId");

      const context = liff.getContext();
      const userId = context.userId;

      if (!groupId) {
        document.getElementById("loading").textContent = "錯誤：缺少群組資訊。";
        return;
      }

      fetchBills(groupId, userId);

      document
        .getElementById("btn-settle-all")
        .addEventListener("click", () => {
          showSettlementPreview(groupId);
        });

      // Modal event listeners
      closeModalBtn.addEventListener("click", () => {
        settleModal.style.display = "none";
      });

      cancelSettleBtn.addEventListener("click", () => {
        settleModal.style.display = "none";
      });

      confirmSettleBtn.addEventListener("click", () => {
        settleAllBills(groupId);
      });

      window.addEventListener("click", (event) => {
        if (event.target == settleModal) settleModal.style.display = "none";
      });
    })
    .catch((err) => {
      console.error("LIFF Initialization failed", err);
      document.getElementById("loading").textContent = "LIFF 初始化失敗。";
    });
});

function fetchBills(groupId, userId) {
  const loadingDiv = document.getElementById("loading");
  const table = document.querySelector(".bill-table");
  const noBillsDiv = document.getElementById("no-bills");
  const tbody = document.getElementById("bill-list");
  const settleContainer = document.getElementById("settle-container");

  fetch(
    `https://bot.patrickzzz.com/liff/liff-api.php?action=get_bills&groupId=${groupId}`
  )
    .then((response) => {
      if (!response.ok)
        throw new Error(`API 請求失敗，狀態碼: ${response.status}`);
      return response.json();
    })
    .then((data) => {
      loadingDiv.style.display = "none";

      if (!data || !Array.isArray(data.bills) || data.bills.length === 0) {
        noBillsDiv.style.display = "block";
        settleContainer.style.display = "none";
        return;
      }

      table.style.display = "table";
      settleContainer.style.display = "block";
      tbody.innerHTML = "";

      data.bills.forEach((bill) => {
        const row = document.createElement("tr");
        row.innerHTML = `
          <td>
            <strong>${escapeHtml(bill.bill_name)}</strong><br>
            <small class="participants">付款人: ${escapeHtml(
              bill.payer_name
            )}</small><br>
            <small class="participants">參與者: ${escapeHtml(
              bill.participants_names.join(", ")
            )}</small>
          </td>
          <td>$${Number(bill.total_amount).toLocaleString()}</td>
          <td class="actions">
            <button class="btn-edit" data-bill-id="${
              bill.bill_id
            }">修改</button>
            <button class="btn-delete" data-bill-id="${
              bill.bill_id
            }">刪除</button>
          </td>
        `;
        tbody.appendChild(row);
      });

      // 為按鈕加上事件監聽
      tbody.querySelectorAll(".btn-edit").forEach((button) => {
        button.addEventListener("click", (e) => {
          const billId = e.target.dataset.billId;
          window.location.href = `liff-form.html?groupId=${groupId}&billId=${billId}`;
        });
      });

      tbody.querySelectorAll(".btn-delete").forEach((button) => {
        button.addEventListener("click", (e) => {
          if (confirm("確定要刪除這筆帳單嗎？"))
            deleteBill(e.target.dataset.billId, e.target, userId);
        });
      });
    })
    .catch((error) => {
      console.error("獲取帳單列表時發生錯誤:", error);
      loadingDiv.textContent = `載入帳單失敗: ${error.message}`;
    });
}

function deleteBill(billId, buttonElement, userId) {
  fetch("../liff/liff-api.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      action: "delete_bill",
      bill_id: billId,
      deleter: userId,
    }),
  })
    .then((response) =>
      response.ok
        ? response.json()
        : response.json().then((err) => Promise.reject(err))
    )
    .then((data) => {
      if (data.status === "success") {
        alert("帳單已刪除！");
        buttonElement.closest("tr").remove();
      } else {
        throw new Error(data.message || "刪除失敗");
      }
    })
    .catch((error) => alert(`刪除失敗: ${error.message || "請檢查主控台"}`));
}

function settleAllBills(groupId) {
  const confirmBtn = document.getElementById("btn-confirm-settle");
  confirmBtn.disabled = true;
  confirmBtn.textContent = "結算中...";

  fetch("../liff/liff-api.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      action: "settle_bills_liff",
      groupId: groupId,
    }),
  })
    .then((response) =>
      response.ok
        ? response.json()
        : response.json().then((err) => Promise.reject(err))
    )
    .then((data) => {
      if (data.status === "success") {
        alert("所有帳單已成功結算！");
        location.reload();
      } else {
        throw new Error(data.message || "結算失敗");
      }
    })
    .catch((error) => {
      alert(`結算失敗: ${error.message || "請檢查主控台"}`);
      confirmBtn.disabled = false;
      confirmBtn.textContent = "確認結算";
    });
}

function showSettlementPreview(groupId) {
  fetch(
    `https://bot.patrickzzz.com/liff/liff-api.php?action=get_balance_report_liff&groupId=${groupId}`
  )
    .then((response) => {
      if (!response.ok) throw new Error("無法獲取結算報告");
      return response.json();
    })
    .then((data) => {
      const balancesDiv = document.getElementById("report-balances");
      const transactionsDiv = document.getElementById("report-transactions");

      balancesDiv.innerHTML = "<h3>帳務總覽</h3>";
      transactionsDiv.innerHTML = "<h3>轉帳建議</h3>";

      if (data.balances && data.balances.length > 0) {
        data.balances.forEach((item) => {
          const balanceEl = document.createElement("div");
          const sign = item.amount > 0 ? "應收" : "應付";
          const color = item.amount > 0 ? "#1DB446" : "#EF4444";
          const amount = Math.abs(item.amount);
          balanceEl.innerHTML = `
                        <span>${escapeHtml(item.userName)}</span>
                        <span style="color: ${color}; font-weight: bold;">${sign} $${amount.toLocaleString(
            undefined,
            { minimumFractionDigits: 2, maximumFractionDigits: 2 }
          )}</span>
                    `;
          balancesDiv.appendChild(balanceEl);
        });
      } else {
        balancesDiv.innerHTML += "<p>無待結算帳務。</p>";
      }

      if (data.transactions && data.transactions.length > 0) {
        data.transactions.forEach((item) => {
          const transactionEl = document.createElement("div");
          const amount = item.amount;
          transactionEl.innerHTML = `
                        <span>${escapeHtml(item.from)} → ${escapeHtml(
            item.to
          )}</span>
                        <span style="font-weight: bold;">$${amount.toLocaleString(
                          undefined,
                          { minimumFractionDigits: 2, maximumFractionDigits: 2 }
                        )}</span>
                    `;
          transactionsDiv.appendChild(transactionEl);
        });
      } else {
        transactionsDiv.innerHTML += "<p>無需轉帳。</p>";
      }

      document.getElementById("settle-modal").style.display = "block";
    })
    .catch((error) => {
      alert(`錯誤: ${error.message}`);
      console.error("Error fetching settlement report:", error);
    });
}

function escapeHtml(unsafe) {
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
