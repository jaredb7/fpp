#ifndef  __FPPOLEDPAGES__
#define  __FPPOLEDPAGES__

#include <vector>
#include <string>
#include <functional>

class OLEDPage {
public:
    enum class OLEDType {
        NONE,
        SMALL,
        SINGLE_COLOR,
        TWO_COLOR
    };
    static void SetOLEDType(OLEDType tp) { oledType = tp; }
    static void SetOLEDOrientationFlipped(bool b) { oledFlipped = b; }
    static OLEDPage *GetCurrentPage() { return currentPage; }
    static void SetCurrentPage(OLEDPage *p);
    
    OLEDPage() : autoDeleteOnHide(false) {}
    virtual ~OLEDPage() {}
    
    virtual void displaying() {}
    virtual void hiding() {}

    virtual bool doIteration(bool &displayOn) { return false; }
    virtual bool doAction(const std::string &action) = 0;

    OLEDPage &autoDelete() { autoDeleteOnHide = true; return *this; }
protected:
    void printString(int x, int y, const std::string &str, bool white = true);
    void printStringCentered(int y, const std::string &str, bool white = true, int textSize = 1);

    
    static OLEDType oledType;
    static bool oledFlipped;
    static OLEDPage *currentPage;
    
    bool autoDeleteOnHide;
};

class TitledOLEDPage : public OLEDPage {
public:
    TitledOLEDPage(const std::string &title);
    virtual ~TitledOLEDPage() {}
    
protected:
    virtual int displayTitle();
    std::string title;
    int numRows;
};

class PromptOLEDPage : public TitledOLEDPage {
public:
    PromptOLEDPage(const std::string &title, const std::string &msg1, const std::string &msg2,
                   const std::vector<std::string> &items);
    PromptOLEDPage(const std::string &title, const std::string &msg1, const std::string &msg2,
                   const std::vector<std::string> &items,
                   const std::function<void (const std::string &)>& itemSelectedCallback);
    virtual ~PromptOLEDPage() {}
    
    virtual void displaying();
    virtual bool doAction(const std::string &action);
    virtual void ItemSelected(const std::string &item);
protected:
    virtual void display();
    
    std::string msg1;
    std::string msg2;
    std::vector<std::string> items;
    int curSelected;
    std::function<void (const std::string &)> itemSelectedCallback;
};

class ListOLEDPage : public TitledOLEDPage {
public:
    ListOLEDPage(const std::string &title, const std::vector<std::string> &items, OLEDPage *parent = nullptr);
    virtual ~ListOLEDPage() {}
    
    virtual void displaying() override;
    virtual bool doAction(const std::string &action) override;

protected:
    virtual void displayScrollArrows(int startY);
    virtual void display();
    
    std::vector<std::string> items;
    int curTop;
    OLEDPage *parent;
};

class MenuOLEDPage : public ListOLEDPage {
public:
    MenuOLEDPage(const std::string &title, const std::vector<std::string> &items, OLEDPage *parent = nullptr);
    MenuOLEDPage(const std::string &title, const std::vector<std::string> &items,
                 const std::function<void (const std::string &)>& itemSelectedCallback,
                 OLEDPage *parent = nullptr);
    virtual ~MenuOLEDPage() {}
    
    virtual void displaying() override;
    virtual bool doAction(const std::string &action) override;
    
    virtual bool itemSelected(const std::string &item);
protected:
    virtual void display();
    int curSelected;
    std::function<void (const std::string &)> itemSelectedCallback;
};

#endif
