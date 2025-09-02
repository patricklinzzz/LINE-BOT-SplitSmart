document.addEventListener("DOMContentLoaded", () => {
  const liffId = "2008005425-9w3Ydy41";

  liff
    .init({ liffId })
    .then(() => {
      if (!liff.isLoggedIn()) {
        liff.login();
        return;
      }

      const urlParams = new URLSearchParams(window.location.search);
      const groupId = urlParams.get("groupId");

      if (!groupId) {
        document.getElementById("loading").textContent = "錯誤：缺少群組資訊。";
        return;
      }

      fetchBills(groupId);
    })
    .catch((err) => {
      console.error("LIFF Initialization failed", err);
      document.getElementById("loading").textContent = "LIFF 初始化失敗。";
    });
});

function fetchBills(groupId) {
  const loadingDiv = document.getElementById("loading");
  const table = document.querySelector(".bill-table");
  const noBillsDiv = document.getElementById("no-bills");
  const tbody = document.getElementById("bill-list");

  fetch(`https://bot.patrickzzz.com/liff/liff-api.php?action=get_bills&groupId=${groupId}`)
    .then((response) => {
      if (!response.ok)
        throw new Error(`API 請求失敗，狀態碼: ${response.status}`);
      return response.json();
    })
    .then((data) => {
      loadingDiv.style.display = "none";

      if (!data || !Array.isArray(data.bills) || data.bills.length === 0) {
        noBillsDiv.style.display = "block";
        return;
      }

      table.style.display = "table";
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
        button.addEventListener("click", (e) =>
          alert(`修改功能開發中 (帳單 ID: ${e.target.dataset.billId})`)
        );
      });

      tbody.querySelectorAll(".btn-delete").forEach((button) => {
        button.addEventListener("click", (e) => {
          if (confirm("確定要刪除這筆帳單嗎？"))
            deleteBill(e.target.dataset.billId, e.target);
        });
      });
    })
    .catch((error) => {
      console.error("獲取帳單列表時發生錯誤:", error);
      loadingDiv.textContent = `載入帳單失敗: ${error.message}`;
    });
}

function deleteBill(billId, buttonElement) {
  fetch("../liff/liff-api.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "delete_bill", bill_id: billId }),
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

function escapeHtml(unsafe) {
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
